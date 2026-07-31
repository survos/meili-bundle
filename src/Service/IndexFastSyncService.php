<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Index;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Message\UpdateIndexInfoMessage;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Symfony\Component\Messenger\MessageBusInterface;

final class IndexFastSyncService
{
    public function __construct(
        private readonly MeiliService $meili,
        private readonly EntityManagerInterface $em,
        private readonly IndexInfoRepository $repo,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * Fetch /indexes, upsert minimal fields, flush.
     * For any index whose server updatedAt is newer than our stored updatedAt (or missing),
     * dispatch UpdateIndexInfoMessage to asynchronously hydrate counts/details.
     *
     * @return array{created:int,updated:int,unchanged:int,enqueued:int,total:int}
     */
    public function fastSync(bool $enqueue = true): array
    {
        $now = new DateTimeImmutable();
        $rows = $this->meili->listIndexesFast(); // very fast, minimal fields
        $total = \count($rows);

        $created = $updated = $unchanged = $enqueued = 0;

        /**
         * @var string $uid
         * @var Index $serverInfo
         */
        foreach ($rows as $uid => $serverInfo) {
            $before = null;

            /** @var IndexInfo|null $localInfo */
            if (!$localInfo = $this->repo->find($uid)) {
                // primaryKey is a required constructor arg; overwritten from $serverInfo below.
                $localInfo = new IndexInfo($uid, 'id');
                $this->em->persist($localInfo);
                $created++;
            } else {
                // Change detection baseline
                $before = [$localInfo->primaryKey, $localInfo->createdAt?->getTimestamp(), $localInfo->updatedAt?->getTimestamp()];
            }

            $priorUpdatedAt = $localInfo->updatedAt;
            $serverUpdatedAt = $serverInfo->getUpdatedAt();

            // Minimal mapping. getPrimaryKey()/getCreatedAt()/getUpdatedAt() lazily fetch
            // the index's raw info from the server on first access (fetchRawInfo() under the hood).
            // IndexInfo's columns are DateTime, but the SDK now returns DateTimeImmutable.
            $localInfo->primaryKey = $serverInfo->getPrimaryKey();
            $createdAtRaw = $serverInfo->getCreatedAt();
            $localInfo->createdAt = $createdAtRaw !== null ? \DateTime::createFromInterface($createdAtRaw) : null;
            $localInfo->updatedAt = $serverUpdatedAt !== null ? \DateTime::createFromInterface($serverUpdatedAt) : null;

            if ($before !== null) {
                $after = [$localInfo->primaryKey, $localInfo->createdAt?->getTimestamp(), $localInfo->updatedAt?->getTimestamp()];
                if ($before !== $after) {
                    $updated++;
                } else {
                    $unchanged++;
                }
            }

            // TODO: dispatch UpdateIndexInfoMessage here once we have a batched/throttled
            // way to do it — per-row messenger dispatch inside a sync loop has previously
            // flooded the queue (see mediary Meilisearch flood incident). IndexInfo also has
            // no needsUpdate column yet to persist this flag across requests.
        }

        $this->em->flush();

        return compact('created','updated','unchanged','enqueued','total');
    }
}
