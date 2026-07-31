<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Meilisearch\Client;
use Meilisearch\Endpoints\Index;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\InvalidResponseBodyException;
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

        [$index, $created] = $this->ensureIndex($indexUid, $primaryKey, $dryRun);

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
            'created'  => $created,
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
     * Returns the Index endpoint (existing or newly created) plus whether it was just created.
     *
     * NOTE: $client->index($uid) never hits the network by itself (lazy Index handle) —
     * existence must be probed with a real call. fetchRawInfo() throws ApiException(404)
     * when the index doesn't exist yet.
     *
     * @return array{0:Index,1:bool}
     */
    private function ensureIndex(string $indexUid, ?string $primaryKey, bool $dryRun): array
    {
        $index = $this->client->index($indexUid);

        try {
            $index->fetchRawInfo();

            return [$index, false];
        } catch (ApiException|InvalidResponseBodyException $exception) {
            if ($exception->httpStatus !== 404) {
                throw $exception;
            }
        }

        if ($dryRun) {
            $this->logger?->info(sprintf('DRY-RUN: would create index "%s" with primaryKey "%s".', $indexUid, $primaryKey ?? '(none)'));

            return [$index, true];
        }

        $this->logger?->info(sprintf('Creating index "%s" (primaryKey=%s).', $indexUid, $primaryKey ?? '(none)'));
        $this->client->createIndex($indexUid, $primaryKey ? ['primaryKey' => $primaryKey] : []);
        // We do NOT wait here; caller commands can choose to wait if desired.

        return [$index, true];
    }
}
