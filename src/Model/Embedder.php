<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Model;

/**
 * Immutable value object describing an embedder profile.
 * Example names: 'small', 'best', 'product_small'
 */
final class Embedder
{
    public function __construct(
        public readonly string $name,
        public readonly string $source,      // 'openAi' (extensible)
        public readonly string $model,       // e.g. 'text-embedding-3-small'
        public readonly ?string $apiKey = null,
        public readonly ?string $forClass = null,  // optional FQCN the embedder targets
        public readonly ?string $template = null   // optional inline template (Twig-from-string)
    ) {}
}
