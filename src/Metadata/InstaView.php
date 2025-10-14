<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class InstaView
{
    /**
     * View-only hints for the InstantSearch UI.
     * Keep this minimal — it's *not* part of the index schema.
     *
     * @param string|null $hitClass     Card/grid style (e.g., 'grid-3', 'grid-4', 'list')
     * @param string|null $template     Twig.js template logical name or route param (your app decides)
     * @param bool|null   $showStats    Show the stats widget by default
     */
    public function __construct(
        public ?string $hitClass = null,
        public ?string $template = null,
        public ?bool $showStats = null,
    ) {}
}
