<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Contracts\TaskStatus;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Service\IndexNameResolver;
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
        private readonly ?IndexNameResolver     $indexNameResolver = null,
        private readonly ?LocaleContext         $localeContext = null,
        private readonly ?LoggerInterface       $logger = null,
    ) {}

    public function __invoke(BatchIndexEntitiesMessage $message): void
    {
        // Compute effective locale and *persist* it back to the message,
        // so everything downstream sees the same value.
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
            'sync'        => $this->messageSync($message),
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

            $runner = function () use ($message): void {
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
            $this->logger?->warning('No Meili index mapping found for entityClass', [
                'entityClass' => $message->entityClass,
            ]);
            return;
        }

        // If locale is null, treat as mono-index (plain) case.
        // If locale is set but producer didn't provide indexName, it is legacy producer behavior.
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

        foreach ($indexes as $mappedKey => $indexCfg) {
            // IMPORTANT:
            // - indexedByClass() historically returned "UIDs" as keys.
            // - With the new IndexNameResolver + registry as source of truth, we can treat
            //   $mappedKey as a BASE key when resolving for a specific locale.
            // We do not regex-strip locale suffixes here; we ask the resolver (when available).
            $resolvedUid = $mappedKey;

            if (!$plainIndex && $message->locale && $this->indexNameResolver) {
                // $mappedKey should be baseName (unprefixed) in the new world
                // If it's already a prefixed uid, uidFor() will re-prefix incorrectly, so
                // we only do this when it looks like a base key (no prefix applied).
                $resolvedUid = $this->resolveUidFromMappingKey($mappedKey, $message->locale);
            }

            $message->indexName = $resolvedUid;

            $runner = function () use ($message, $resolvedUid, $plainIndex): void {
                $this->logger?->info(
                    $plainIndex
                        ? 'Applying mapped index for entityClass'
                        : 'Applying derived localized index for entityClass (legacy producer)',
                    [
                        'indexName' => $resolvedUid,
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
        $this->entityManager->clear($entityClass);

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
        // SETTINGS
        //
        // Settings in MeiliService are keyed by *base name* (unprefixed), e.g. "amst".
        // Messages carry a UID (prefixed, maybe locale-suffixed), e.g. "bts_amst_nl".
        //
        // Previously we regex-stripped prefix/locale. That is brittle.
        // If IndexNameResolver exists, prefer its source-of-truth to compute the base key
        // via the registry (MeiliService should already know base keys for settings).
        //
        // In the absence of a reverse lookup in the resolver, we keep a conservative fallback.
        $settingsKey = $this->settingsKeyForUid($indexName, $locale);

        $indexSettings = $this->meiliService->getIndexSetting($settingsKey);
        $client = $this->meiliService->getMeiliClient();

        if (!$indexSettings) {
            $this->logger?->warning('Missing indexSettings for index', [
                'indexName'     => $indexName,
                'settingsKey'   => $settingsKey,
                'entityClass'   => $entityClass,
                'locale'        => $locale,
                'availableKeys' => \array_keys($this->meiliService->getAllSettings()),
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
            'sync'          => $this->messageSync($message),
        ]);

        //
        // Load and normalize entities under the scoped locale
        //
        $metadata        = $this->entityManager->getClassMetadata($entityClass);
        $repo            = $this->entityManager->getRepository($entityClass);
        $identifierField = $metadata->getSingleIdentifierFieldName();

        $sync = $this->messageSync($message);

        // IMPORTANT:
        // Always pass $primaryKey on add-documents (first ingestion establishes PK).
        // When sync is enabled, wait for completion and fail loudly on failure.
        if ($message->reload) {
            $iter = $this->yieldNormalizedDocs(
                $repo,
                $identifierField,
                $message->entityData,
                $persisted,
            );
            $taskId = $this->uploader->uploadDocuments($index, $iter, $primaryKey);
            if ($sync) {
                $task = $client->getTask($taskId)->wait();
                if ($task->getStatus() !== TaskStatus::Succeeded) {
                    $this->logger?->error('Meilisearch task failed (sync mode)', [$index->getUid(), $task->getStatus(), $task->getError()]);
                }
            }
        } else {
            // If entityData is already an array of docs, NDJSON uploader is fine.
            // If it's a list of IDs (legacy), reload should have been set.
//            dd($message->entityData);
            $taskUid = $this->uploader->uploadDocuments($index, $message->entityData, $primaryKey);
            $task = $client->getTask($taskUid)->wait();
            if ($task->getStatus() !== TaskStatus::Succeeded) {
                $this->logger?->error('Meilisearch task failed (async mode)', [$index->getUid(), $task->getStatus(), $task->getError()]);
            }
        }
    }

    private function getMeiliIndex(?string $indexName, string $entityClass, ?string $locale): Indexes
    {
        if ($indexName) {
            $this->logger?->info('getMeiliIndex(): using explicit indexName', [
                'indexName' => $indexName,
                'locale'    => $locale,
            ]);

            // autoCreate: true is fine; PK is established on first addDocuments via $primaryKey
            return $this->meiliService->getOrCreateIndex($indexName, autoCreate: true);
        }

        //
        // Fallback (rare): derive a UID from class + locale policy.
        // Prefer IndexNameResolver when available.
        //
        $short = (new \ReflectionClass($entityClass))->getShortName();
        $loc   = $locale ?: $this->localeContext?->getDefault();

        $classMapping = $this->meiliService->indexedByClass()[$entityClass] ?? [];

        $baseKey = \count($classMapping) === 1
            ? (string) \array_key_first($classMapping)
            : \strtolower($short);

        $derivedUid = $baseKey;

        if ($this->indexNameResolver && $loc) {
            // If $baseKey is actually a UID already, this may not be safe to re-resolve.
            // However this fallback path is rare; we keep the old behavior when resolver isn't usable.
            $derivedUid = $this->resolveUidFromMappingKey($baseKey, $loc);
        } elseif ($this->meiliService->isMultiLingual && $loc) {
            $derivedUid = $this->meiliService->localizedUid($baseKey, $loc);
        }

        $this->logger?->info('getMeiliIndex(): derived fallback indexName', [
            'baseKey'    => $baseKey,
            'derivedUid' => $derivedUid,
            'locale'     => $loc,
        ]);

        return $this->meiliService->getOrCreateIndex($derivedUid, autoCreate: true);
    }

    /**
     * Conservative “mapping key -> uid” resolution.
     *
     * If the mapping key already looks like a UID (e.g. starts with configured prefix),
     * return it unchanged. Otherwise treat it as a base name and resolve using IndexNameResolver.
     */
    private function resolveUidFromMappingKey(string $mappingKey, string $locale): string
    {
        $prefix = $this->meiliService->getPrefix();

        if ($prefix && \str_starts_with($mappingKey, $prefix)) {
            // Already a UID
            return $mappingKey;
        }

        // Treat as base name
        return $this->indexNameResolver
            ? $this->indexNameResolver->uidFor($mappingKey, $locale)
            : $mappingKey;
    }

    /**
     * Settings keys are base names; message carries UIDs.
     *
     * Today we do not have a guaranteed reverse lookup (uid -> baseName) exposed here,
     * so we keep a safe fallback: strip prefix once, strip locale suffix when provided.
     *
     * If/when MeiliRegistry exposes reverse resolution, wire it here and delete the fallback.
     */
    private function settingsKeyForUid(string $indexUid, ?string $locale): string
    {
        // Prefer a reverse lookup if MeiliService grows one (future-proof, no BC break).
        if (method_exists($this->meiliService, 'baseNameForUid')) {
            /** @var string $base */
            $base = $this->meiliService->baseNameForUid($indexUid);
            return $base;
        }

        // Fallback: previous behavior (kept for BC)
        return $this->baseSettingsKeyForIndexName($indexUid, $locale);
    }

    private function baseSettingsKeyForIndexName(string $indexUid, ?string $locale): string
    {
        $key = $indexUid;

        $prefix = $this->meiliService->getPrefix();
        if ($prefix && \str_starts_with($key, $prefix)) {
            $key = \substr($key, \strlen($prefix));
        }

        if ($locale) {
            $suffix = '_' . \strtolower($locale);
            if (\str_ends_with($key, $suffix)) {
                $key = \substr($key, 0, -\strlen($suffix));
            }
        }

        return $key;
    }

    /**
     * Determine whether we should wait for tasks.
     *
     * Supports either:
     * - $message->sync (preferred)
     * - $message->wait (fallback)
     */
    private function messageSync(BatchIndexEntitiesMessage $message): bool
    {
        foreach (['sync', 'wait'] as $prop) {
            if (\property_exists($message, $prop)) {
                $v = $message->{$prop};
                return \is_bool($v) ? $v : (bool) $v;
            }
        }

        return false;
    }

    /**
     * Wait for the task if sync is enabled and we have a task uid.
     * Fail loudly when the task fails.
     */
    private function awaitIfSync(Indexes $index, Task $task, bool $sync, ?string $indexName, string $primaryKey): void
    {
        if (!$sync) {
            return;
        }

        // Normalize task uid from whatever uploader returns.
        $uid = null;

        if (\is_int($taskUid)) {
            $uid = $taskUid;
        } elseif (\is_array($taskUid)) {
            $uid = $taskUid['taskUid'] ?? $taskUid['uid'] ?? $taskUid['task_id'] ?? null;
            $uid = \is_int($uid) ? $uid : null;
        } elseif (\is_object($taskUid)) {
            foreach (['getTaskUid', 'getUid'] as $m) {
                if (\method_exists($taskUid, $m)) {
                    $v = $taskUid->{$m}();
                    if (\is_int($v)) {
                        $uid = $v;
                        break;
                    }
                }
            }
        }

        if (!$uid) {
            $this->logger?->warning('Sync requested but no task uid was returned from uploader; cannot wait', [
                'indexName'  => $indexName,
                'primaryKey' => $primaryKey,
            ]);
            return;
        }

        $this->logger?->info('Waiting for Meilisearch task completion (sync mode)', [
            'taskUid'     => $uid,
            'indexName'   => $indexName,
            'primaryKey'  => $primaryKey,
        ]);

        $task = $this->waitForTask($index, $taskUid);

        $status = \is_array($task) ? ($task['status'] ?? null) : null;
        if ($status !== 'succeeded') {
            $error = \is_array($task) ? ($task['error'] ?? null) : null;
            $msg = sprintf(
                'Meilisearch task %d did not succeed (status=%s) for index "%s" (primaryKey=%s).',
                $uid,
                (string) ($status ?? 'unknown'),
                (string) ($indexName ?? $index->getUid()),
                $primaryKey
            );

            $this->logger?->error($msg, [
                'task' => $task,
            ]);

            throw new \RuntimeException($msg . ($error ? ' ' . json_encode($error) : ''));
        }
    }

    /**
     * Wait using whichever API is available in the current SDK / MeiliService wrapper.
     *
     * @return array<string,mixed>
     */
    private function waitForTask(Indexes $index, Task $task): array
    {
        // Newer SDKs support waitForTask directly on Indexes.
        if (\method_exists($index, 'waitForTask')) {
            /** @var array<string,mixed> $task */
            $task = $index->waitForTask($taskUid);
            return $task;
        }

        // If MeiliService provides a wait helper, prefer it.
        if (\method_exists($this->meiliService, 'waitForTask')) {
            /** @var array<string,mixed> $task */
            $task = $this->meiliService->waitForTask($taskUid);
            return $task;
        }

        throw new \RuntimeException('Sync mode requested, but no waitForTask() implementation is available.');
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
            if (!$entity) {
                $this->logger?->warning('Entity not found for ID', ['id' => $id]);
                continue;
            }

            $doc = $this->payloadBuilder->build($entity, $persisted);
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
