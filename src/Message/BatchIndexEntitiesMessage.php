<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Message;

/**
 * Batch indexing message.
 */
final class BatchIndexEntitiesMessage
{
    /**
     * @param array<mixed> $entityData list of IDs or list of normalized docs
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array $entityData,
        public readonly ?bool $reload = null,
        public readonly ?string $primaryKeyName = null,
        public readonly ?string $transport = null, // legacy
        public ?string $locale = null,
        public ?string $indexName = null,
        public readonly ?bool $dry = null,
        public readonly ?bool $cost = null,
        public readonly bool $sync = false,
        public readonly bool $wait = false,
        public readonly bool $dump = false,
    ) {}
}
