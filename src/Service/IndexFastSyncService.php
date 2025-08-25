<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
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
         * @var Indexes $serverInfo
         */
        foreach ($rows as $uid=> $serverInfo) {
//            dd($serverInfo);
//            $uid = (string)$serverInfo['uid'];
//            if ($uid === '') { continue; }


//            $serverCreatedAt = isset($serverInfo['createdAt']) && is_string($serverInfo['createdAt']) ? new DateTimeImmutable($serverInfo['createdAt']) : null;
//            $serverUpdatedAt = isset($serverInfo['updatedAt']) && is_string($serverInfo['updatedAt']) ? new DateTimeImmutable($serverInfo['updatedAt']) : null;

            /** @var IndexInfo|null $localInfo */
            if (!$localInfo = $this->repo->find($uid)) {
                $localInfo = new IndexInfo($uid);
                $this->em->persist($localInfo);
                $created++;
            } else {
                // Change detection baseline
                $lastUpdated = $localInfo->updatedAt; // before we persist
                $before = [$localInfo->primaryKey, $localInfo->createdAt?->getTimestamp(), $localInfo->updatedAt?->getTimestamp()];
            }

            // Minimal mapping
            $localInfo->primaryKey = $serverInfo->getPrimaryKey();
            $localInfo->createdAt  = $serverInfo->getCreatedAt();
            $localInfo->updatedAt = $serverInfo->getUpdatedAt();
//            $localInfo->updatedAt  = $serverUpdatedAt ?? $localInfo->updatedAt;
//            $localInfo->lastSyncedAt = $now; // do this in the handler

            if (isset($before)) {
                $after = [$localInfo->primaryKey, $localInfo->createdAt?->getTimestamp(), $localInfo->updatedAt?->getTimestamp()];
                if ($before !== $after) {
                    $updated++;
                } else {
                    $unchanged++;
                }
            }

            // Decide whether to enqueue a deeper hydration
            if ($enqueue) {
                $needs = false;
                // if never updated or updated since our last update
                if ( (!$localInfo->updatedAt || $serverInfo->getUpdatedAt() > $localInfo->updatedAt)) {
                    $needs = true;
                    $localInfo->needsUpdate = $needs;
                }
////                if ($localInfo->numDocuments === 0) {
////                    $needs = true;
////                }
//                if ($needs) {
//                    $this->bus->dispatch(new UpdateIndexInfoMessage($uid));
//                    $enqueued++;
//                }
            }
        }

        $this->em->flush();



        return compact('created','updated','unchanged','enqueued','total');
    }
}
