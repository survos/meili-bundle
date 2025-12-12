<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BootstrapBundle\Service\ContextService;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsMessageHandler]
final class BatchIndexEntitiesMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MeiliPayloadBuilder    $payloadBuilder,
        private readonly SettingsService        $settingsService,
        private readonly NormalizerInterface    $normalizer,
        private readonly MeiliService           $meiliService,
        private readonly MeiliNdjsonUploader    $uploader,
        private readonly ?LocaleContext         $localeContext = null,
        private readonly ?LoggerInterface       $logger = null,
    ) {}

    public function __invoke(BatchIndexEntitiesMessage $message): void
    {
        // Compute effective locale and *persist* it back to the message,
        // so everything downstream (including apply/yield) sees the same value.
        $effectiveLocale = $message->locale ?: $this->localeContext?->getDefault();

        if ($effectiveLocale !== $message->locale) {
            $this->logger?->info('Normalizing message locale', [
                'original'  => $message->locale,
                'effective' => $effectiveLocale,
            ]);
            $message->locale = $effectiveLocale;
        }

        $this->logger?->info('BatchIndexEntitiesMessageHandler: received message', [
            'entityClass' => $message->entityClass,
            'indexName'   => $message->indexName,
            'locale'      => $message->locale,
            'reload'      => $message->reload,
            'ids'         => \is_iterable($message->entityData) ? \count((array) $message->entityData) : null,
        ]);

        //
        // ⭐ PRIMARY PATH: Producer already set indexName (localized UID)
        //
        if ($message->indexName) {
            $this->logger?->info('Using explicit indexName from producer', [
                'indexName' => $message->indexName,
                'locale'    => $message->locale,
            ]);

            $runner = function () use ($message) {
                $this->apply($message);
            };

            if ($this->localeContext && $message->locale) {
                $this->logger?->info('LocaleContext->run() for explicit indexName', [
                    'locale'    => $message->locale,
                    'indexName' => $message->indexName,
                ]);
                $this->localeContext->run($message->locale, $runner);
            } else {
                if ($message->locale && !$this->localeContext) {
                    $this->logger?->warning('Locale set but LocaleContext missing (no BabelBundle?)', [
                        'locale'    => $message->locale,
                        'indexName' => $message->indexName,
                    ]);
                }
                $runner();
            }

            return;
        }
//
// 🌱 DEFAULT / MAPPED PATH: derive indexName(s) from indexedByClass()
//
        $classIndexes = $this->meiliService->indexedByClass();
        $indexes      = $classIndexes[$message->entityClass] ?? [];
        $count        = \count($indexes);

        if ($count === 0) {
            // This really IS exceptional.
            $this->logger?->warning('No Meili index mapping found for entityClass', [
                'entityClass' => $message->entityClass,
            ]);
            return;
        }

// Heuristic: if locale is null, this is the normal "plain index" case.
// Only treat it as "legacy fallback" (and log louder) when locale is set but
// the producer still didn't provide an indexName.
        $plainIndex = ($message->locale === null);

        $this->logger?->info(
            $plainIndex
                ? 'Using default index mapping from indexedByClass()'
                : 'Deriving localized index mapping from indexedByClass() (legacy producer)',
            [
                'entityClass' => $message->entityClass,
                'count'       => $count,
                'locale'      => $message->locale,
            ]
        );

        foreach ($indexes as $indexName => $index) {
            $message->indexName = $indexName;

            $runner = function () use ($message, $indexName, $plainIndex) {
                $this->logger?->info(
                    $plainIndex
                        ? 'Applying mapped index for entityClass'
                        : 'Applying derived localized index for entityClass (legacy producer)',
                    [
                        'indexName' => $indexName,
                        'locale'    => $message->locale,
                    ]
                );

                $this->apply($message);
            };

            if ($this->localeContext && $message->locale) {
                $this->localeContext->run($message->locale, $runner);
            } else {
                $runner();
            }
        }

    }

    private function apply(BatchIndexEntitiesMessage $message): void
    {
        $entityClass = $message->entityClass;
        $indexName   = $message->indexName;
        $locale      = $message->locale;

        // 🔑 Important: drop cached entities so postLoad runs under the new locale
        $this->logger?->info('Clearing EntityManager identity map for class before indexing', [
            'entityClass' => $entityClass,
            'indexName'   => $indexName,
            'locale'      => $locale,
        ]);
        $this->entityManager->clear($entityClass); // or clear() for everything

        // Optional: verify what LocaleContext thinks is current
        $currentLocale = null;
        if ($this->localeContext && method_exists($this->localeContext, 'get')) {
            $currentLocale = $this->localeContext->get();
        }

        $this->logger?->info('BatchIndexEntitiesMessageHandler.apply(): entering', [
            'entityClass'   => $entityClass,
            'indexName'     => $indexName,
            'messageLocale' => $locale,
            'contextLocale' => $currentLocale,
        ]);

        //
        // Index for this message
        //
        $index = $this->getMeiliIndex($indexName, $entityClass, $locale);

        //
        // SETTINGS: use base settings for localized index names like "movies_en"
        //
        $settingsKey = $indexName;
        if (!isset($this->meiliService->settings[$settingsKey])
            && $this->meiliService->isMultiLingual
            && $locale
        ) {
            $suffix = '_' . $locale;
            if (\str_ends_with($indexName, $suffix)) {
                $baseKey = \substr($indexName, 0, -\strlen($suffix));
                if (isset($this->meiliService->settings[$baseKey])) {
                    $this->logger?->info('Using base settings for localized index', [
                        'localizedIndex' => $indexName,
                        'baseIndex'      => $baseKey,
                        'locale'         => $locale,
                    ]);
                    $settingsKey = $baseKey;
                } else {
                    $this->logger?->warning('Localized index settings not found and base settings missing', [
                        'localized'      => $indexName,
                        'baseAttempt'    => $baseKey,
                        'availableKeys'  => \array_keys($this->meiliService->settings),
                    ]);
                }
            }
        }

        $indexSettings = $this->meiliService->settings[$settingsKey] ?? null;

        if (!$indexSettings) {
            $this->logger?->warning('Missing indexSettings for index', [
                'indexName'     => $indexName,
                'settingsKey'   => $settingsKey,
                'entityClass'   => $entityClass,
                'locale'        => $locale,
                'availableKeys' => \array_keys($this->meiliService->settings),
            ]);
            return;
        }

        $persisted  = $indexSettings['persisted']  ?? [];
        $primaryKey = $indexSettings['primaryKey'] ?? 'id';

        $this->logger?->info('Applying documents to index', [
            'entityClass'   => $entityClass,
            'indexUid'      => $index->getUid(),
            'indexName'     => $indexName,
            'settingsKey'   => $settingsKey,
            'messageLocale' => $locale,
            'contextLocale' => $currentLocale,
            'primaryKey'    => $primaryKey,
            'reload'        => $message->reload,
        ]);

        //
        // Load and normalize entities under the scoped locale
        //
        $metadata        = $this->entityManager->getClassMetadata($entityClass);
        $repo            = $this->entityManager->getRepository($entityClass);
        $identifierField = $metadata->getSingleIdentifierFieldName();
//        ($message->locale === 'es') && dd($message, $identifierField);


        if ($message->reload) {
            $iter = $this->yieldNormalizedDocs(
                $repo,
                $identifierField,
                $message->entityData,
                $persisted,
            );
            $this->uploader->uploadDocuments($index, $iter, $primaryKey);
        } else {
            $this->uploader->uploadDocuments($index, $message->entityData, $primaryKey);
        }
    }

    private function getMeiliIndex(?string $indexName, string $entityClass, ?string $locale): Indexes
    {
        if ($indexName) {
            $this->logger?->info('getMeiliIndex(): using explicit indexName', [
                'indexName' => $indexName,
                'locale'    => $locale,
            ]);
            return $this->meiliService->getOrCreateIndex($indexName, autoCreate: true);
        }

        //
        // Fallback (rare)
        //
        $short = (new \ReflectionClass($entityClass))->getShortName();
        $loc   = $locale ?: $this->localeContext?->getDefault();

        $classMapping = $this->meiliService->indexedByClass()[$entityClass] ?? [];
        $baseUid = \count($classMapping) === 1
            ? \array_key_first($classMapping)
            : \strtolower($short);

        $derivedUid = $baseUid;
        if ($this->meiliService->isMultiLingual && $loc) {
            $derivedUid = $this->meiliService->localizedUid($baseUid, $loc);
        }

        $this->logger?->info('getMeiliIndex(): derived fallback indexName', [
            'baseUid'    => $baseUid,
            'derivedUid' => $derivedUid,
            'locale'     => $loc,
        ]);

        return $this->meiliService->getOrCreateIndex($derivedUid, autoCreate: true);
    }

    /**
     * Generator: normalize and yield docs for NDJSON upload.
     *
     * @param iterable<int|string> $ids
     */
    private function yieldNormalizedDocs(object $repo, string $idField, iterable $ids, array $persisted): \Generator
    {
        foreach ($ids as $id) {
            $entity = $repo->find($id);
//            if ($this->localeContext->get() === 'en') dd($this->localeContext->get(), $entity->niveau1);
            if (!$entity) {
                $this->logger?->warning('Entity not found for ID', ['id' => $id]);
                continue;
            }

            $doc = $this->payloadBuilder->build($entity, $persisted);
            $targetLocale = $this->localeContext->get();
            $doc = SurvosUtils::removeNullsAndEmptyArrays($doc);

            if (!\is_array($doc)) {
                $this->logger?->warning('payloadBuilder returned non-array', [
                    'id'   => $id,
                    'type' => \get_debug_type($doc),
                ]);
                continue;
            }

            yield $doc;
        }
    }
}
