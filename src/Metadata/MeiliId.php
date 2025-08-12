<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;
use \Attribute as Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class MeiliId
{
    /**
     * @param string|null $label defaults to property
     * @param int $showMoreThreshold if less than this number, there's no show more in the search
     */
    public function __construct(
        public ?string $label=null,
        public int $showMoreThreshold=8,
    ) {
    }
}
