<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Facet
{
    /**
     * @param string|null $label  Display label (defaults to field name)
     * @param int $order          Lower first; overrides Select(filterable) order when present
     * @param int $showMoreThreshold  If facet has < threshold values, don't show "more"
     * @param string|null $type   UI hint (e.g. 'RangeSlider')
     * @param string|null $format UI hint (e.g. 'price'), used by your frontend
     * @param bool|null $visible  Force show/hide (null = inherit/global)
     * @param string[] $tagsAny   Visible only when runtime context contains any of these tags (e.g. ['dev'])
     * @param array<string,mixed> $props Arbitrary UI props (icons, flags, etc.) forwarded to your widget
     */
    public function __construct(
        public ?string $label = null,
        public int $order = 0,
        public int $showMoreThreshold = 8,
        public ?string $type = null,
        public ?string $format = null,
        public ?bool $visible = null,
        public array $tagsAny = [],
        public array $props = [],
    ) {}
}
