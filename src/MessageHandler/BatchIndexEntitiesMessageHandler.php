<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\MessageHandler;

use App\Service\AppService;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class BatchIndexEntitiesMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsService        $settingsService,
        private readonly NormalizerInterface    $normalizer,
        private readonly MeiliService           $meiliService,
        private readonly ?LocaleContext         $localeContext=null, // from BabelBundle
        private readonly ?LoggerInterface       $logger = null,
    ) {}

    #[AsMessageHandler]
    public function batchIndexEntities(BatchIndexEntitiesMessage $message): void
    {

        $locale = $message->locale ?: $this->localeContext?->getDefault();
        // if locale is null, skip it?  Or always have a locale?

        if (!$this->localeContext) {
            $this->apply($message);
        } else {
            // Scope everything (DB hydration + normalization) to the message locale.
            $this->localeContext->run($locale, fn($message) => $this->apply($message));
        }

    }

    private function apply($message): void {

            $metadata        = $this->entityManager->getClassMetadata($message->entityClass);
            $repo            = $this->entityManager->getRepository($message->entityClass);
            $identifierField = $metadata->getSingleIdentifierFieldName();
            $groups          = $this->settingsService->getNormalizationGroups($message->entityClass);

            // Pick the target Meili index (per-locale index name logic lives in your service)
            $index = $this->getMeiliIndex($message->indexName, $message->entityClass, $message->locale);

            $payloadThreshold = 50_000_000; // ~50 MB
            $documents        = [];
            $payloadSize      = 0;

            if ($message->reload) {
                // Load rows by PKs in $message->entityData, normalize with $groups,
                // batch by payload size, flush to Meili as needed.
                $this->meiliService->loadAndFlush(
                    message:          $message,
                    repo:             $repo,
                    identifierField:          $identifierField,
                    groups:           $groups,
                    payloadSize:      $payloadSize,
                    documents:        $documents,
                    payloadThreshold: $payloadThreshold,
                    meiliIndex:            $index,
                );
            } else {
    // Already-normalized documents provided (rare, but supported)
    $documents = $message->entityData;
    $this->flushToMeili($index, $documents, \count($documents));
}
}


    /**
     * Resolve the target Meilisearch index to write to.
     * Prefer message-provided indexName; else derive from entity + locale.
     */
    private function getMeiliIndex(?string $indexName, string $entityClass, ?string $locale): Indexes
    {
        if ($indexName) {
            return $this->meiliService->getOrCreateIndex($indexName, autoCreate: true);
        }

        $short = (new \ReflectionClass($entityClass))->getShortName();
        $loc   = $locale ?: $this->localeContext?->getDefault();
        $name  = $this->meiliService->getPrefixedIndexName(sprintf('%s%s', $short, $loc ? "_$loc" : ''));

        return $this->meiliService->getOrCreateIndex($name, autoCreate: true);
    }

    /**
     * Your existing implementation should:
     *  - pull entities by PKs in $message->entityData
     *  - normalize with $groups (PostLoadHydrator will have run in $locale scope)
     *  - accumulate into $documents, respecting $payloadThreshold (bytes)
     *  - call $this->flushToMeili($index, $batch, $count) per batch
     *
     * Kept as a separate method to avoid blowing up this file.
     */
    private function loadAndFlush(
        BatchIndexEntitiesMessage $message,
        object $repo,
        string $idField,
        array $groups,
        int &$payloadSize,
        array &$documents,
        int $payloadThreshold,
        Indexes $index,
    ): void {
        dd($documents);
        // IMPLEMENTATION NOTE:
        // foreach ($message->entityData as $id) { $e = $repo->find($id); $row = $this->normalizer->normalize($e, null, ['groups'=>$groups]); ... }
        // use json_encode($row) length to track $payloadSize, flush when above threshold.
        // This method existed before; re-use your version.
        throw new \LogicException('loadAndFlush() must be implemented (reuse your existing version).');
    }

    /**
     * Thin wrapper to write a batch to Meilisearch.
     * Keep your existing error handling / waitForTask policy here.
     */
    private function flushToMeili(Indexes $index, array $docs, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $task = $index->addDocuments($docs);
        $this->meiliService->waitForTask($task);
        $this->logger?->info('Meili flush', ['count' => $count, 'index' => $index->getUid()]);
    }
}
