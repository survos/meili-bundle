<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Model;

/**
 * Immutable plan for indexing a class into a specific locale index.
 */
final class IndexPlan
{
    public function __construct(
        public readonly string  $entityClass,
        public readonly ?string $locale,         // e.g. 'en'
        public readonly string  $indexName,      // fully-resolved, including prefix + _{locale} suffix
        public readonly string  $primaryKeyName, // e.g. 'id' or external key
        public readonly ?string $transport = null
    ) {}
}
