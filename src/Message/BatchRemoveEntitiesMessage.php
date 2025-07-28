<?php
// Batch message classes for optimized MeiliSearch operations

declare(strict_types=1);

namespace Survos\MeiliBundle\Message;


/**
 * Message to batch remove multiple entities of the same class from MeiliSearch
 */
class BatchRemoveEntitiesMessage
{
    /**
     * @param string $entityClass
     * @param array<mixed> $entityIds Array of entity IDs to remove
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array $entityIds,
    ) {
    }
}

