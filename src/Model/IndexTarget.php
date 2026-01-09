<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Model;

/**
 * Planned Meilisearch index target.
 */
final class IndexTarget
{
    public function __construct(
        public readonly string $base,   // unprefixed registry key
        public readonly string $uid,    // resolved Meili UID (prefix applied)
        public readonly string $class,  // entity FQCN
        public readonly ?string $locale,
        public readonly string $kind,   // base|source|target
    ) {}
}
