<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class MeiliIndex
{
    private Fields $displaySel;
    private Fields $filterableSel;
    private Fields $sortableSel;
    private Fields $searchableSel;
    private Fields $persistedSel;

    public function __construct(
        public ?string $name = null,
        public ?string $class = null,
        public ?string $primaryKey = 'id',
        /** Base serializer groups for payload normalization */
//        public ?array  $groups = null,
        public Fields|array   $persisted = [],

        /** Meili settings selections (fields + optional groups override) */
        Fields|array   $displayed = ['*'],
        Fields|array   $filterable = [],
        Fields|array   $sortable = [],
        Fields|array   $searchable = [],

        /** Reserved / extra knobs */
        public array   $faceting = [],
        public array   $filter = [],
    ) {
        $this->displaySel    = Fields::from($displayed);
        $this->filterableSel = Fields::from($filterable);
        $this->sortableSel   = Fields::from($sortable);
        $this->searchableSel = Fields::from($searchable);
        // used by the serializer
        $this->persistedSel = Fields::from($this->persisted);
    }

    // Accessors used by the compiler pass (keeps the pass dumb & stable)
    /** @return string[] */ public function displayFields(): array    { return $this->displaySel->fields; }
    /** @return string[] */ public function filterableFields(): array { return $this->filterableSel->fields; }
    /** @return string[] */ public function sortableFields(): array   { return $this->sortableSel->fields; }
    /** @return string[] */ public function searchableFields(): array { return $this->searchableSel->fields; }
    /** @return string[] */ public function persistedFields(): array { return $this->persistedSel->fields; }

    /** @return string[]|null */ public function displayGroups(): ?array    { return $this->displaySel->groups; }
    /** @return string[]|null */ public function filterableGroups(): ?array { return $this->filterableSel->groups; }
    /** @return string[]|null */ public function sortableGroups(): ?array   { return $this->sortableSel->groups; }
    /** @return string[]|null */ public function searchableGroups(): ?array { return $this->searchableSel->groups; }
    /** @return string[]|null */ public function persistedGroups(): ?array { return $this->persistedSel->groups; }

    public function defaultName(string $fqcn): string
    {
        $short = substr($fqcn, (int) (strrpos($fqcn, '\\') ?: -1) + 1);
        return strtolower($short);
    }
}
