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
 * ─────────────────────────────────────────────────────────────────────────
 *  ⚠️  TODO — REMOVE `Fields` VALUE OBJECT  ⚠️
 *
 *  This attribute currently accepts `Fields|array` for the field-selection
 *  properties (persisted, displayed, filterable, sortable, searchable) and
 *  normalizes everything to plain arrays in the constructor — this lets
 *  Symfony's XmlDumper serialize the attribute since no Fields objects
 *  live on public surface anymore.
 *
 *  THE CONSTRUCTOR-LEVEL FIX IS DELIBERATELY MINIMAL. The proper migration:
 *
 *    1. Stop accepting `Fields|array`. Accept `array` only — entities
 *       declare `persisted: ['fields' => [...], 'groups' => [...]]`.
 *    2. Drop the private $displaySel/$filterableSel/$sortableSel/etc.
 *       and the *Fields() / *Groups() typed accessors. The compiler pass
 *       reads `$this->displayed['fields']` directly.
 *    3. Once meili-bundle has no internal use of `Fields`, delete the
 *       class.
 *    4. The "select a subset of properties via groups + explicit fields"
 *       concept is generic (search, API, grids, LLM serialization) — if
 *       it deserves a proper home, that's `survos/field-bundle`, not
 *       meili-bundle. But field-bundle's per-property `#[Field]` model
 *       may make a top-down Fields class unnecessary; verify before
 *       reviving it.
 *
 *  See conversation 2026-05 for context. Goal: do this later in this
 *  session if time, otherwise queue for the survos 3.0 release work.
 * ─────────────────────────────────────────────────────────────────────────
 *
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
    /** @var array{fields?: list<string>, groups?: list<string>|null}|list<string> */
    public array $persisted;
    /** @var array{fields?: list<string>, groups?: list<string>|null}|list<string> */
    public array $displayed;
    /** @var array{fields?: list<string>, groups?: list<string>|null}|list<string> */
    public array $filterable;
    /** @var array{fields?: list<string>, groups?: list<string>|null}|list<string> */
    public array $sortable;
    /** @var array{fields?: list<string>, groups?: list<string>|null}|list<string> */
    public array $searchable;

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
        Fields|array $persisted = [],

        /** Meili settings selections */
        Fields|array $displayed = ['*'],
        Fields|array $filterable = [],
        Fields|array $sortable = [],
        Fields|array $searchable = [],

        /** Reserved / extra knobs (unchanged) */
        public array $faceting = [],
        public array $filter = [],
        /** @var array<string> */
        public array $embedders = [],

        /**
         * Chat workspace names this index participates in.
         * Example: chats: ['meili_assistant']
         * No indexing cost — chat queries are billed at runtime only.
         * @var array<string>
         */
        public array $chats = [],

        /**
         * Optional chat prompt overrides keyed by prompt slot.
         * Example: prompts: ['system' => 'Always include [id:{value}] using field {{ primaryKey }}.']
         * @var array<string,string>
         */
        public array $prompts = [],

        /**
         * UI hints consumed by the bundle's frontend (insta controller & templates).
         * Example:
         *   ['columns'=>4,'template'=>'@App/hits/card.html.twig','cardClass'=>'meili-card','layout'=>'bootstrap']
         */
        public array $ui = []
    ) {
        // Normalize Fields|array → array on the public properties so the
        // attribute is container-serializable. See big TODO above.
        $this->persisted   = self::asArray($persisted);
        $this->displayed   = self::asArray($displayed);
        $this->filterable  = self::asArray($filterable);
        $this->sortable    = self::asArray($sortable);
        $this->searchable  = self::asArray($searchable);

        $this->displaySel    = Fields::from($this->displayed);
        $this->filterableSel = Fields::from($this->filterable);
        $this->sortableSel   = Fields::from($this->sortable);
        $this->searchableSel = Fields::from($this->searchable);
        $this->persistedSel  = Fields::from($this->persisted);
        $this->enabled ??= true;
    }

    /**
     * Coerce Fields|array into the array-with-keys shape Fields::from() can re-parse.
     * Internal helper for the constructor; remove with the Fields class itself.
     *
     * @param  Fields|array<int|string,mixed> $v
     * @return array<int|string,mixed>
     */
    private static function asArray(Fields|array $v): array
    {
        if ($v instanceof Fields) {
            return ['fields' => $v->fields, 'groups' => $v->groups];
        }
        return $v;
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
