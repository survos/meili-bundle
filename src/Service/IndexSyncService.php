<?php // sync live data with IndexInfo doctrine entities
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Message\UpdateIndexInfoMessage;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class IndexSyncService implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    public function __construct(
        private readonly MeiliService $meili,
        private readonly EntityManagerInterface $em,
        private readonly IndexInfoRepository $repo,
    ) {}

    /**
     * Walk every index the Meilisearch server actually has (filtered by prefix)
     * and upsert an IndexInfo record for each one.  This is the complement of
     * sync(): sync() only knows about indexes the local app has configured via
     * #[MeiliIndex] attributes; syncFromServer() discovers everything — including
     * indexes created by other apps (md, zm, …) that share the same prefix.
     *
     * @return array{created:int,updated:int,unchanged:int,pruned:int,total:int}
     */
    public function syncFromServer(bool $prune = false): array
    {
        $now      = new \DateTime();
        $prefix   = $this->meili->getPrefix() ?? '';
        $liveMap  = $this->meili->listIndexesFast(); // uid => Indexes

        $created = $updated = $unchanged = 0;
        $seenUids = [];

        foreach ($liveMap as $uid => $liveIndex) {
            // Filter to our prefix (empty prefix = accept all)
            if ($prefix !== '' && !str_starts_with($uid, $prefix)) {
                continue;
            }

            $seenUids[] = $uid;

            try {
                $stats        = $liveIndex->stats();
                $numDocuments = (int)($stats['numberOfDocuments'] ?? 0);
            } catch (\Throwable) {
                $numDocuments = 0;
            }

            $primaryKey = $liveIndex->getPrimaryKey() ?? 'id';
            $createdAt  = $liveIndex->getCreatedAt() ? \DateTime::createFromInterface($liveIndex->getCreatedAt()) : null;
            $updatedAt  = $liveIndex->getUpdatedAt() ? \DateTime::createFromInterface($liveIndex->getUpdatedAt()) : null;

            $indexInfo = $this->repo->find($uid);
            $isNew     = $indexInfo === null;

            if ($isNew) {
                $indexInfo = new IndexInfo($uid, $primaryKey);
                $this->em->persist($indexInfo);
            }

            $before = [
                $indexInfo->primaryKey,
                $indexInfo->documentCount,
                $indexInfo->createdAt?->getTimestamp(),
                $indexInfo->updatedAt?->getTimestamp(),
            ];

            $indexInfo->primaryKey    = $primaryKey;
            $indexInfo->documentCount = $numDocuments;
            $indexInfo->createdAt     = $createdAt;
            $indexInfo->updatedAt     = $updatedAt;
            $indexInfo->lastIndexed   = $now;
            // settings stays empty for server-discovered indexes — we don't know
            // the local attribute config.  The uid itself is the link.

            $after = [
                $indexInfo->primaryKey,
                $indexInfo->documentCount,
                $indexInfo->createdAt?->getTimestamp(),
                $indexInfo->updatedAt?->getTimestamp(),
            ];

            if ($isNew) {
                $created++;
            } elseif ($before === $after) {
                $unchanged++;
            } else {
                $updated++;
            }
        }

        $pruned = 0;

        // Always enforce prefix: remove any rows that don't belong to this prefix.
        // This keeps the local catalog authoritative — no foreign-app rows polluting queries.
        $qb = $this->em->createQueryBuilder()->select('i')->from(IndexInfo::class, 'i');
        if ($prefix !== '') {
            // Rows outside the prefix should never be here.
            $qb->where('i.indexName NOT LIKE :prefix')
               ->setParameter('prefix', $prefix . '%');
        } elseif ($prune && $seenUids !== []) {
            // No prefix configured: only prune rows not seen on the server (original behaviour).
            $qb->where('i.indexName NOT IN (:uids)')
               ->setParameter('uids', $seenUids);
        } else {
            $qb = null; // nothing to prune
        }

        if ($qb !== null) {
            foreach ($qb->getQuery()->getResult() as $o) {
                $this->em->remove($o);
                $pruned++;
            }
        }

        // When prefix is set and prune requested, also remove seen-prefix rows no longer on server.
        if ($prune && $prefix !== '' && $seenUids !== []) {
            $orphans = $this->em->createQueryBuilder()
                ->select('i')->from(IndexInfo::class, 'i')
                ->where('i.indexName NOT IN (:uids)')
                ->andWhere('i.indexName LIKE :prefix')
                ->setParameter('uids', $seenUids)
                ->setParameter('prefix', $prefix . '%')
                ->getQuery()->getResult();
            foreach ($orphans as $o) {
                $this->em->remove($o);
                $pruned++;
            }
        }

        $this->em->flush();

        return [
            'created'   => $created,
            'updated'   => $updated,
            'unchanged' => $unchanged,
            'pruned'    => $pruned,
            'total'     => count($seenUids),
        ];
    }

    /**
     * @param callable(string $uid): ?string $localeResolver  Optional
     * @param callable(string $uid): ?string $datasetResolver Optional
     * @return array{created:int,updated:int,unchanged:int,pruned:int,total:int}
     */
    public function sync(
        bool $prune = false,
        ?callable $localeResolver = null,
        ?callable $datasetResolver = null
    ): array {
        $now = new \DateTime();

        // baseSettings is keyed by baseName; each value is the settings array for that index.
        $baseSettings = $this->meili->getRawIndexSettings();

        $prefix = $this->meili->getPrefix() ?? '';

        $created = $updated = $unchanged = 0;
        $syncedIndexNames = [];

        foreach ($baseSettings as $baseName => $settings) {
            // Compute the fully-prefixed UID for this base index.
            $uid = $this->meili->uidForBase($baseName, null);

            // Skip indexes that don't match the configured prefix.
            if ($prefix !== '' && !str_starts_with($uid, $prefix)) {
                continue;
            }

            $syncedIndexNames[] = $uid;

            // Fetch live stats from Meilisearch server (numberOfDocuments, isIndexing, etc.)
            $primaryKey = (string)($settings['primaryKey'] ?? 'id');
            try {
                $liveIndex = $this->meili->getMeiliClient()->getIndex($uid);
                $stats = $this->meili->getMeiliClient()->index($uid)->stats();
                $numDocuments = (int)($stats['numberOfDocuments'] ?? 0);
                $createdAt = isset($liveIndex->createdAt) ? new DateTimeImmutable($liveIndex->createdAt) : null;
                $updatedAt = isset($liveIndex->updatedAt) ? new DateTimeImmutable($liveIndex->updatedAt) : null;
            } catch (\Throwable) {
                // Index not yet created on server — still record it locally with zero docs.
                $numDocuments = 0;
                $createdAt = null;
                $updatedAt = null;
            }

            // Create-or-load by PK (indexName = uid).
            if (!$indexInfo = $this->repo->find($uid)) {
                $indexInfo = new IndexInfo($uid, $primaryKey);
                $this->em->persist($indexInfo);
            }

            $before = [
                $indexInfo->primaryKey,
                $indexInfo->documentCount,
                $indexInfo->createdAt?->getTimestamp(),
                $indexInfo->updatedAt?->getTimestamp(),
            ];

            // Map live data + config settings → entity.
            $indexInfo->primaryKey    = $primaryKey;
            $indexInfo->settings      = $settings;
            $indexInfo->documentCount = $numDocuments;
            $indexInfo->createdAt     = $createdAt;
            $indexInfo->updatedAt     = $updatedAt;
            $indexInfo->lastIndexed   = $now;

            $after = [
                $indexInfo->primaryKey,
                $indexInfo->documentCount,
                $indexInfo->createdAt?->getTimestamp(),
                $indexInfo->updatedAt?->getTimestamp(),
            ];

            if ($before === $after) {
                $unchanged++;
            } elseif ($before[0] === null) {
                $created++;
            } else {
                $updated++;
            }
        }

        $pruned = 0;
        if ($prune && $syncedIndexNames !== []) {
            $orphans = $this->em->createQueryBuilder()
                ->select('i')->from(IndexInfo::class, 'i')
                ->where('i.indexName NOT IN (:names)')
                ->setParameter('names', $syncedIndexNames)
                ->getQuery()->getResult();
            foreach ($orphans as $o) {
                $this->em->remove($o);
                $pruned++;
            }
        }

        $this->em->flush();

        return [
            'created'   => $created,
            'updated'   => $updated,
            'unchanged' => $unchanged,
            'pruned'    => $pruned,
            'total'     => count($syncedIndexNames),
        ];
    }

    #[AsMessageHandler()]
    public function handleIndexSyncMessage(UpdateIndexInfoMessage $message): void
    {
        $this->syncFromServer();
    }
}
