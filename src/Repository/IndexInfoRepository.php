<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Repository;

use Survos\MeiliBundle\Entity\IndexInfo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndexInfo>
 */
class IndexInfoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndexInfo::class);
    }

    /**
     * @return IndexInfo[]
     */
    public function findByPixieCode(string $pixieCode): array
    {
        return $this->findBy(['pixieCode' => $pixieCode]);
    }

    public function findByPixieCodeAndLocale(string $pixieCode, string $locale): ?IndexInfo
    {
        return $this->findOneBy([
            'pixieCode' => $pixieCode,
            'locale' => $locale
        ]);
    }

    /**
     * @return IndexInfo[]
     */
    public function findProcessing(): array
    {
        return $this->createQueryBuilder('ii')
            ->where('ii.status IN (:statuses)')
            ->setParameter('statuses', ['queued', 'processing'])
            ->getQuery()
            ->getResult();
    }

    /**
     * @return IndexInfo[]
     */
    public function findStale(?\DateInterval $interval = null): array
    {
        $interval = $interval ?? new \DateInterval('PT1H'); // Default 1 hour
        $staleTime = (new \DateTime())->sub($interval);

        return $this->createQueryBuilder('ii')
            ->where('ii.status IN (:statuses)')
            ->andWhere('ii.lastIndexed < :staleTime')
            ->setParameter('statuses', ['queued', 'processing'])
            ->setParameter('staleTime', $staleTime)
            ->getQuery()
            ->getResult();
    }
}
