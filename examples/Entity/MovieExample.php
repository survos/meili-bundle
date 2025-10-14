<?php

declare(strict_types=1);

namespace Examples\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MeiliBundle\Metadata\Facet;
use Survos\MeiliBundle\Metadata\FacetWidget;
use Survos\MeiliBundle\Metadata\MeiliIndex;

#[ORM\Entity]
#[MeiliIndex(filterable: ['genres','keywords','releaseYear'], sortable: ['releaseYear'])]
final class MovieExample
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $id = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Facet(widget: FacetWidget::RangeSlider, format: 'minutes')]
    public ?int $runtime = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[Facet(label: 'Genre', widget: FacetWidget::RefinementList, sortMode: 'alpha', collapsed: false, limit: 12, showMoreLimit: 50)]
    public ?array $genres = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true], nullable: true)]
    #[Facet(widget: FacetWidget::RefinementList, label: 'Production Companies', collapsed: true, sortMode: 'count')]
    public ?array $productionCompanies = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Facet(widget: FacetWidget::RangeSlider, label: 'Year', sortMode: 'alpha', collapsed: false)]
    public ?int $releaseYear = null;
}
