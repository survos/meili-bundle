<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Facet
{
    /**
     * @param string|null                 $label        Display label (defaults to field/method name)
     * @param int                         $order        Lower first; affects facet ordering in UI
     * @param int                         $showMoreThreshold If < threshold values, hide "Show more" affordance
     * @param FacetWidget|string|null     $widget       Widget to render (enum preferred; string accepted)
     * @param string|null                 $format       UI hint (e.g., 'price', 'monthIndex')
     * @param bool|null                   $visible      Force show/hide (null = inherit/global)
     * @param string[]                    $tagsAny      Visible only when runtime context contains any tag
     * @param array<string,mixed>         $props        Arbitrary UI props forwarded to the widget
     *
     * Common real-world options (mirrored into frontend config):
     * @param 'count'|'alpha'|null        $sortMode     RefinementList/Menu sorting (by count or alphabetic)
     * @param bool|null                   $collapsed    Start collapsed (panel); user toggles persist in UI
     * @param int|null                    $limit        Initial number of facet values (RefinementList/Menu)
     * @param int|null                    $showMoreLimit Max number when expanded
     * @param bool|null                   $searchable   Enable facet search (RefinementList)
     * @param array<string,string>        $lookup       Map raw value -> human label (transformItems)
     */
    public function __construct(
        public ?string $label = null,
        public int $order = 0,
        public int $showMoreThreshold = 8,
        public FacetWidget|string|null $widget = null,
        public ?string $format = null,
        public ?bool $visible = null,
        public array $tagsAny = [],
        public array $props = [],

        // convenience options that the compiler pass forwards to JS
        public ?string $sortMode = null,
        public ?bool $collapsed = null,
        public ?int $limit = null,
        public ?int $showMoreLimit = null,
        public ?bool $searchable = null,
        public array $lookup = [],
    ) {}
}
