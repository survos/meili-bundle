<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Message\UpdateIndexInfoMessage;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

final class IndexSyncService
{
    public function __construct(
        private readonly MeiliService $meili,
        private readonly EntityManagerInterface $em,
        private readonly IndexInfoRepository $repo,
        private readonly ObjectMapperInterface $mapper,
        private readonly LoggerInterface $logger,
    ) {}

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
        $now = new DateTimeImmutable();

        $rows = $this->meili->listIndexes(); // [['uid'=>..., 'primaryKey'=>..., 'createdAt'=>..., 'updatedAt'=>..., 'numDocuments'=>...], ...]
        $uids = array_map(static fn($i) => (string)$i['uid'], $rows);

        $created = $updated = $unchanged = 0;

        foreach ($rows as $row) {
            $uid = (string)$row['uid'];

            // create-or-load by PK (uid)
            $indexInfo = $this->repo->find($uid) ?? new IndexInfo($uid);
            if ($indexInfo->lastSyncedAt === null) {
                $this->em->persist($indexInfo);
            }

            $before = [
                $indexInfo->primaryKey,
                $indexInfo->numDocuments,
                $indexInfo->createdAt?->getTimestamp(),
                $indexInfo->updatedAt?->getTimestamp(),
                $indexInfo->locale,
                $indexInfo->dataset,
            ];

            // Map server payload → entity (we do NOT set uid; it's already set)
            $source = (object) $row;
            $this->mapper->map($source, $indexInfo);

            // Normalize date strings to DateTimeImmutable if needed
            if (isset($row['createdAt']) && is_string($row['createdAt'])) {
                $indexInfo->createdAt = new DateTimeImmutable($row['createdAt']);
            }
            if (isset($row['updatedAt']) && is_string($row['updatedAt'])) {
                $indexInfo->updatedAt = new DateTimeImmutable($row['updatedAt']);
            }

            // Derive dataset/locale once (unless already set)
            $indexInfo->locale  ??= $localeResolver ? $localeResolver($uid) : null;
            $indexInfo->dataset ??= $datasetResolver ? $datasetResolver($uid) : null;

            $indexInfo->lastSyncedAt = $now;

            $after = [
                $indexInfo->primaryKey,
                $indexInfo->numDocuments,
                $indexInfo->createdAt?->getTimestamp(),
                $indexInfo->updatedAt?->getTimestamp(),
                $indexInfo->locale,
                $indexInfo->dataset,
            ];

            if ($before === $after) {
                $unchanged++;
            } elseif ($before[0] === null && $indexInfo->lastSyncedAt === $now) {
                $created++;
            } else {
                $updated++;
            }
        }

        $pruned = 0;
        if ($prune) {
            $orphans = $this->em->createQueryBuilder()
                ->select('i')->from(IndexInfo::class, 'i')
                ->where('i.uid NOT IN (:uids)')
                ->setParameter('uids', $uids)
                ->getQuery()->getResult();
            foreach ($orphans as $o) {
                $this->em->remove($o);
                $pruned++;
            }
        }

        $this->em->flush();

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'pruned' => $pruned,
            'total' => count($rows),
        ];
    }

    #[AsMessageHandler()]
    public function handleIndexSyncMessage(UpdateIndexInfoMessage $message): void
    {
        $info = $this->meili->getMeiliClient()->stats($message->uid);
        dd($info);
        dd($message);

    }
}
