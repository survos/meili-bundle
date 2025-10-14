<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

enum FacetWidget: string
{
    case RefinementList = 'RefinementList';
    case RangeSlider    = 'RangeSlider';
    case Toggle         = 'Toggle';
    case NumericMenu    = 'NumericMenu';
    case RatingMenu     = 'RatingMenu';
    case Menu           = 'Menu';
}
