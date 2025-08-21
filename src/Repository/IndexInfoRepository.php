<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\MeiliBundle\Entity\IndexInfo;

/** @extends ServiceEntityRepository<IndexInfo> */
class IndexInfoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndexInfo::class);
    }

    /** @return IndexInfo[] */
    public function findByUids(array $uids): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.uid IN (:uids)')->setParameter('uids', $uids)
            ->getQuery()->getResult();
    }
}
