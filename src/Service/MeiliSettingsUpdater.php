<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Survos\MeiliBundle\Metadata\MeiliIndex;

/**
 * Creates index if needed (using primaryKey), and applies settings (including 'embedders') in one call.
 */
final class MeiliSettingsUpdater
{
    public function __construct(
        private readonly Client $client,
        private readonly ?LoggerInterface $logger = null,
    ) {
        dd("is this called?  It looks like it might have been an experiment");

    }

    /**
     * Update index settings from an entity class' #[MeiliIndex] attribute.
     *
     * @param class-string $entityClass
     * @return array{indexUid:string, created:bool, settings:mixed, taskUid:int|null}
     */
    public function updateFromAttribute(string $entityClass, bool $purge = false, bool $dryRun = false): array
    {
        [$indexUid, $primaryKey, $settings] = $this->extractFromAttribute($entityClass);

        $index = $this->ensureIndex($indexUid, $primaryKey, $dryRun);

        if ($purge && !$dryRun) {
            $this->logger?->info(sprintf('Purging all documents from index "%s".', $indexUid));
            $index->deleteAllDocuments(); // single fast call
        }

        $taskUid = null;

        if ($dryRun) {
            $this->logger?->info(sprintf('DRY-RUN: would call updateSettings(%s) on "%s".', json_encode($settings, JSON_PRETTY_PRINT), $indexUid));
        } else {
            $this->logger?->info(sprintf('Updating settings for "%s".', $indexUid));
            $task = $index->updateSettings($settings); // embedders can be part of this payload
            $taskUid = $task['taskUid'] ?? null;
        }

        return [
            'indexUid' => $indexUid,
            'created'  => $index instanceof Indexes ? false : true, // only true on first creation path below; see ensureIndex()
            'settings' => $settings,
            'taskUid'  => $taskUid,
        ];
    }

    /**
     * @return array{0:string,1:string|null,2:array}
     */
    private function extractFromAttribute(string $entityClass): array
    {
        $rc = new ReflectionClass($entityClass);
        $attrs = $rc->getAttributes(MeiliIndex::class);
        if (!$attrs) {
            throw new \RuntimeException(sprintf('Entity "%s" is missing #[MeiliIndex] attribute.', $entityClass));
        }
        /** @var MeiliIndex $ai */
        $ai = $attrs[0]->newInstance();

        // Build settings payload from attribute
        $settings = array_filter([
            'filterableAttributes' => $ai->filterable ?: null,
            'sortableAttributes'   => $ai->sortable   ?: null,
            'searchableAttributes' => $ai->searchable ?: null,
            'displayedAttributes'  => $ai->displayed  ?: null,
            'stopWords'            => $ai->stopWords  ?: null,
            'synonyms'             => $ai->synonyms   ?: null,
            'rankingRules'         => $ai->rankingRules ?: null,
            // NEW: embedders included directly in settings
            'embedders'            => $ai->embedders  ?: null,
        ], static fn($v) => $v !== null);

        return [$ai->indexUid, $ai->primaryKey, $settings];
    }

    /**
     * Creates the index if missing, using the provided primaryKey.
     * Returns the Indexes endpoint (existing or newly created).
     */
    private function ensureIndex(string $indexUid, ?string $primaryKey, bool $dryRun): Indexes
    {
        try {
            return $this->client->index($indexUid);
        } catch (\Throwable) {
            // If access fails because it doesn't exist, create it.
        }

        if ($dryRun) {
            $this->logger?->info(sprintf('DRY-RUN: would create index "%s" with primaryKey "%s".', $indexUid, $primaryKey ?? '(none)'));
            // Return a lazy endpoint anyway so subsequent code paths don’t branch
            return $this->client->index($indexUid);
        }

        $this->logger?->info(sprintf('Creating index "%s" (primaryKey=%s).', $indexUid, $primaryKey ?? '(none)'));
        $task = $this->client->createIndex($indexUid, $primaryKey ? ['primaryKey' => $primaryKey] : []);
        // We do NOT wait here; caller commands can choose to wait if desired.
        return $this->client->index($indexUid);
    }
}
