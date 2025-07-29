<?php

namespace Survos\MeiliBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Streams primary keys for a given Doctrine entity in batches.
 */
class DoctrinePrimaryKeyStreamer
{
    private Connection $connection;
    private ClassMetadata $metadata;
    private string $tableName;
    private string $primaryKey;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $entityClass
    ) {
        $this->connection = $em->getConnection();
        $this->metadata = $em->getClassMetadata($entityClass);

        // Only supports single primary key
        $idFields = $this->metadata->getIdentifierColumnNames();
        if (count($idFields) !== 1) {
            throw new \RuntimeException(sprintf(
                'Entity %s has a composite primary key, which is not supported by %s.',
                $entityClass,
                self::class
            ));
        }

        $this->tableName = $this->metadata->getTableName();
        $this->primaryKey = $idFields[0];
    }

    public function stream(int $batchSize = 10000): \Generator
    {
        $offset = 0;

        while (true) {
            $sql = sprintf(
                'SELECT %s AS pk FROM %s ORDER BY %s ASC LIMIT %d OFFSET %d',
                $this->primaryKey,
                $this->tableName,
                $this->primaryKey,
                (int) $batchSize,
                (int) $offset
            );

            $result = $this->connection->executeQuery($sql);
            $ids = $result->fetchFirstColumn(); // returns [id1, id2, ...]

            if (!$ids) {
                break;
            }

            yield $ids;

            $offset += $batchSize;
        }
    }

    /**
     * Stream IDs one by one (instead of in batches).
     *
     * @param int $batchSize
     * @return \Generator<int>
     */
    public function streamOneByOne(int $batchSize = 10000): \Generator
    {
        foreach ($this->stream($batchSize) as $batch) {
            foreach ($batch as $id) {
                yield $id;
            }
        }
    }
}
