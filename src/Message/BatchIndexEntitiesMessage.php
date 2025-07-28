<?php
// Batch message classes for optimized MeiliSearch operations

declare(strict_types=1);

namespace Survos\MeiliBundle\Message;

/**
 * Message to batch index multiple entities of the same class in MeiliSearch
 */
class BatchIndexEntitiesMessage
{
    /**
     * @param string $entityClass
     * @param array<array> $entitiesData Array of normalized entity data
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array $entitiesData,
    ) {
    }
}

