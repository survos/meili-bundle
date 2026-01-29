<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

use Attribute;
use function strrpos;
use function substr;
use function strtolower;

/**
 * Declarative Meilisearch index configuration + UI hints used by SurvosMeiliBundle.
 *
 * Notes
 * - Field selections accept either a Fields object or a plain array of field names (optionally with groups inside Fields).
 * - The $ui map carries *presentation hints* consumed by the bundle's frontend:
 *     ui: [
 *       'columns'        => 3,                                  // int: grid columns for hits
 *       'template'       => '@SurvosMeili/components/hits/_card.neutral.html.twig', // string: Twig path
 *       'cardClass'      => 'meili-card shadow',                // string: wrapper CSS classes
 *       'layout'         => 'neutral',                          // string: 'neutral' | 'bootstrap' | 'tailwind' | ...
 *       'showScore'      => true,                               // bool: show ranking score on cards
 *       'showJsonButton' => true                                // bool: show JSON modal button on cards
 *     ]
 */
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
        public ?string $primaryKey = null,
        public ?bool $enabled = null,

        /**
         * If true, Survos\MeiliBundle\EventListener\DoctrineEventListener will auto-dispatch
         * BatchIndexEntitiesMessage / BatchRemoveEntitiesMessage on entity changes.
         *
         * If false, the entity is "known to Meili" (schema/settings exist) but indexing is
         * entirely manual (e.g. Pixie rows via pixie:index).
         */
        public bool $autoIndex = true,

        /** Fields persisted/normalized for the index payload (serializer source) */
        public Fields|array $persisted = [],

        /** Meili settings selections */
        public Fields|array $displayed = ['*'],
        public Fields|array $filterable = [],
        public Fields|array $sortable = [],
        public Fields|array $searchable = [],

        /** Reserved / extra knobs (unchanged) */
        public array $faceting = [],
        public array $filter = [],
        /** @var array<string> */
        public array $embedders = [],

        /**
         * UI hints consumed by the bundle's frontend (insta controller & templates).
         * Example:
         *   ['columns'=>4,'template'=>'@App/hits/card.html.twig','cardClass'=>'meili-card','layout'=>'bootstrap']
         */
        public array $ui = []
    ) {
        $this->displaySel    = Fields::from($this->displayed);
        $this->filterableSel = Fields::from($this->filterable);
        $this->sortableSel   = Fields::from($this->sortable);
        $this->searchableSel = Fields::from($this->searchable);
        $this->persistedSel  = Fields::from($this->persisted);
        $this->enabled ??= true;
    }

    // --- Accessors used by the compiler pass (keeps the pass dumb & stable)

    /** @return string[] */
    public function displayFields(): array { return $this->displaySel->fields; }

    /** @return string[] */
    public function filterableFields(): array { return $this->filterableSel->fields; }

    /** @return string[] */
    public function sortableFields(): array { return $this->sortableSel->fields; }

    /** @return string[] */
    public function searchableFields(): array { return $this->searchableSel->fields; }

    /** @return string[] */
    public function persistedFields(): array { return $this->persistedSel->fields; }

    /** @return string[]|null */
    public function displayGroups(): ?array { return $this->displaySel->groups; }

    /** @return string[]|null */
    public function filterableGroups(): ?array { return $this->filterableSel->groups; }

    /** @return string[]|null */
    public function sortableGroups(): ?array { return $this->sortableSel->groups; }

    /** @return string[]|null */
    public function searchableGroups(): ?array { return $this->searchableSel->groups; }

    /** @return string[]|null */
    public function persistedGroups(): ?array { return $this->persistedSel->groups; }

    /**
     * UI map (raw). Keys are free-form but the bundle recognizes:
     * columns, template, cardClass, layout, showScore, showJsonButton.
     * @return array<string,mixed>
     */
    public function ui(): array
    {
        return $this->ui;
    }

    /** Convenience getters for common UI hints */
    public function columns(): ?int
    {
        $v = $this->ui['columns'] ?? null;
        return is_numeric($v) ? max(1, (int) $v) : null;
    }

    public function template(): ?string
    {
        $tpl = $this->ui['template'] ?? null;
        return is_string($tpl) && $tpl !== '' ? $tpl : null;
    }

    public function cardClass(): ?string
    {
        $cls = $this->ui['cardClass'] ?? null;
        return is_string($cls) && $cls !== '' ? $cls : null;
    }

    public function layout(): ?string
    {
        $layout = $this->ui['layout'] ?? null;
        return is_string($layout) && $layout !== '' ? $layout : null;
    }

    public function showScore(): ?bool
    {
        return array_key_exists('showScore', $this->ui) ? (bool) $this->ui['showScore'] : null;
    }

    public function showJsonButton(): ?bool
    {
        return array_key_exists('showJsonButton', $this->ui) ? (bool) $this->ui['showJsonButton'] : null;
    }

    public function defaultName(string $fqcn): string
    {
        $short = substr($fqcn, (int) (strrpos($fqcn, '\\') ?: -1) + 1);
        return strtolower($short);
    }
}
