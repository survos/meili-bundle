<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Compiler;

use ReflectionClass;
use Survos\MeiliBundle\Metadata\Facet;
use Survos\MeiliBundle\Metadata\InstaView;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MeiliBundle\Metadata\FacetWidget;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Util\GroupFieldResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;
// use this in your compiler pass file
use Doctrine\ORM\Mapping as ORM;


final class MeiliIndexPass implements CompilerPassInterface
{
    private GroupFieldResolver $groupResolver;

    public function __construct(?GroupFieldResolver $groupResolver = null)
    {
        $this->groupResolver = $groupResolver ?? new GroupFieldResolver();
    }

    public function process(ContainerBuilder $container): void
    {
        $scanDirs = (array) ($container->hasParameter('survos_meili.entity_dirs')
            ? $container->getParameter('survos_meili.entity_dirs') : []);
        if ($scanDirs === []) {
            $scanDirs = [(string) $container->getParameter('kernel.project_dir') . '/src/Entity'];
        }
        $bag = $container->getParameterBag();
        $scanDirs = array_map(static fn($p) => (string) $bag->resolveValue($p), $scanDirs);

        $indexEntities = [];
        $indexSettings = [];

        foreach ($scanDirs as $dir) {
            foreach ($this->classesIn($dir) as $fqcn) {
                if (!class_exists($fqcn)) { continue; }

                $ref = new ReflectionClass($fqcn);
                $facetMap = $this->collectFacetMap($ref);

                foreach ($ref->getAttributes(MeiliIndex::class) as $attr) {
                    /** @var MeiliIndex $cfg */
                    $cfg = $attr->newInstance();

                    $class = $cfg->class ?? $fqcn;
                    $name  = $cfg->name ?? $cfg->defaultName($class);

                    $display    = $this->groupResolver->expandUnion($class, $cfg->displayFields(),    $cfg->displayGroups());
                    $filterable = $this->groupResolver->expandUnion($class, $cfg->filterableFields(), $cfg->filterableGroups());
                    $sortable   = $this->groupResolver->expandUnion($class, $cfg->sortableFields(),   $cfg->sortableGroups());
                    $searchable = $this->groupResolver->expandUnion($class, $cfg->searchableFields(), $cfg->searchableGroups());

                    $persistedFields = $cfg->persistedFields();
                    $persistedGroups = $cfg->persistedGroups();
                    $persisted       = $this->groupResolver->expandUnion($class, $persistedFields, $persistedGroups);

                    foreach (array_keys($facetMap) as $field) {
                        if (!in_array($field, $filterable, true)) {
                            $filterable[] = $field;
                        }
                    }

                    if ($persisted !== []) {
                        $strict = (bool) ($container->hasParameter('survos_meili.strict')
                            ? $container->getParameter('survos_meili.strict') : false);
                        $strict = true;
                        $toCheck = [
                            'filterable' => $filterable,
                            'sortable'   => $sortable,
                            'searchable' => ($searchable === ['*']) ? [] : $searchable,
                        ];

                        foreach ($toCheck as $label => $fields) {
                            foreach ($fields as $f) {
                                if ($f === '*') { continue; }
                                if (!in_array($f, $persisted, true)) {
                                    $msg = sprintf(
                                        '[Survos/Meili] %s field "%s" is not in persisted for index "%s" (%s); it will not be effective.',
                                        $label, $f, $name, $class
                                    );
                                    if ($strict) {
                                        throw new \InvalidArgumentException($msg);
                                    }
                                    @trigger_error($msg, E_USER_WARNING);
                                }
                            }
                        }
                    }

                    $indexEntities[$name] = $class;

                    $indexSchema = [
                        'displayedAttributes'  => $display ?: ['*'],
                        'filterableAttributes' => array_values(array_unique($filterable)),
                        'sortableAttributes'   => array_values(array_unique($sortable)),
                        'searchableAttributes' => $searchable ?: ['*'],
                        'faceting' => [
                            'sortFacetValuesBy' => ['*' => 'count'],
                            'maxValuesPerFacet' => 1000,
                        ],
                    ];

                    $instaViewHints = [];
                    foreach ($ref->getAttributes(InstaView::class) as $ivAttr) {
                        /** @var InstaView $iv */
                        $iv = $ivAttr->newInstance();
                        $instaViewHints = array_filter([
                            'hitClass'  => $iv->hitClass,
                            'template'  => $iv->template,
                            'showStats' => $iv->showStats,
                        ], static fn($v) => $v !== null);
                        break; // at most one per class
                    }


                    // 1) Ensure every MeiliIndex::filterable has a facet config (defaults when not annotated)
                    foreach ($filterable as $field) {
                        if (!isset($facetMap[$field])) {
                            $facetMap[$field] = $this->defaultFacetConfig($ref, $field);
                        }
                    }

// 2) Order facets: first by MeiliIndex::filterable order, then by class declaration order
                    $facetMap = $this->orderFacets($facetMap, $filterable);
                    // check for invalid facets
                    foreach (array_keys($facetMap) as $field) {
                        if (!in_array($field, $filterable, true)) {
                            // Make it a hard error in strict mode:
                            throw new \InvalidArgumentException(
                                sprintf('[Survos/Meili] Facet "%s" is not listed in MeiliIndex::filterable for "%s".', $field, $name)
                            );
                        }
                    }
                    if (!$cfg->primaryKey) {
                        $idFields = $this->findIdentifierFields($class); // e.g. ['accessionNumber']
                        if (count($idFields) !== 1) {
                            throw new \RuntimeException(sprintf("%s needs a single primary key field tagged as #[ORM\Id].", $class));
                        }
                        $cfg->primaryKey = $idFields[0];
                    }
                    $indexSettings[$class][$name] = [
                        'schema'     => $indexSchema,
                        'primaryKey' => $cfg->primaryKey,
                        'persisted'  => (array) $cfg->persisted,
                        'class'      => $class,
                        'facets'     => $facetMap,
                        'embedders'  => $cfg->embedders,
                        'autoIndex' => $cfg->autoIndex,
                        'ui' => $cfg->ui,
                    ];
                }
            }
        }

// Merge with any previously-registered indexes (e.g. Pixie)
        $prevEntities = $container->hasParameter('meili.index_entities')
            ? (array) $container->getParameter('meili.index_entities') : [];

        $prevSettings = $container->hasParameter('meili.index_settings')
            ? (array) $container->getParameter('meili.index_settings') : [];

        $prevNames = $container->hasParameter('meili.index_names')
            ? (array) $container->getParameter('meili.index_names') : [];

// Decide precedence: usually "new pass wins" for collisions
        $mergedEntities = array_replace($prevEntities, $indexEntities);
        $mergedSettings = array_replace_recursive($prevSettings, $indexSettings);

        $mergedNames = array_values(array_unique(array_merge(
            $prevNames,
            array_keys($mergedEntities)
        )));
        sort($mergedNames);

        $container->setParameter('meili.index_names', $mergedNames);
        $container->setParameter('meili.index_entities', $mergedEntities);
        $container->setParameter('meili.index_settings', $mergedSettings);

        if ($container->hasDefinition(MeiliService::class)) {
            $def = $container->getDefinition(MeiliService::class);
            $def->setArgument('$indexedEntities', $mergedEntities);
            $def->setArgument('$indexSettings', $mergedSettings);
        }

    }

    /** @return iterable<class-string> */
    private function classesIn(string $dir): iterable
    {
        if (!is_dir($dir)) { return; }
        $finder = (new Finder())->files()->in($dir)->name('*.php');
        foreach ($finder as $file) {
            $src = @file_get_contents($file->getPathname());
            if ($src === false) { continue; }
            if (!preg_match('/namespace\s+([^;]+);/m', $src, $ns)) { continue; }
            if (!preg_match('/\bclass\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\b/m', $src, $cls)) { continue; }
            yield trim($ns[1]) . '\\' . trim($cls[1]);
        }
    }

    private function collectFacetMap(ReflectionClass $ref): array
    {
        $map = [];

        $collect = static function (Facet $f, string $name): array {
            // Accept enum or string, normalize to string for JS
            $widget = $f->widget;
            if ($widget instanceof FacetWidget) {
                $widget = $widget->value;
            }

            return [
                'label'            => $f->label ?? $name,
                'order'            => $f->order,
                'showMoreThreshold'=> $f->showMoreThreshold,
                'widget'           => $widget,              // <-- widget (new)
                // Legacy interop: if someone still had "type", keep a copy for now
                'type'             => $widget,              // (soft-compat; can be removed later)
                'format'           => $f->format,
                'visible'          => $f->visible,
                'tagsAny'          => $f->tagsAny,
                'props'            => $f->props,
                // common real-world options mirrored into UI config
                'sortMode'         => $f->sortMode,
                'collapsed'        => $f->collapsed,
                'limit'            => $f->limit,
                'showMoreLimit'    => $f->showMoreLimit,
                'searchable'       => $f->searchable,
                'lookup'           => $f->lookup,
            ];
        };

        foreach ($ref->getProperties() as $p) {
            foreach ($p->getAttributes(Facet::class) as $a) {
                /** @var Facet $f */
                $f = $a->newInstance();
                $map[$p->getName()] = $collect($f, $p->getName());
            }
        }
        foreach ($ref->getMethods() as $m) {
            foreach ($m->getAttributes(Facet::class) as $a) {
                /** @var Facet $f */
                $f = $a->newInstance();
                $map[$m->getName()] = $collect($f, $m->getName());
            }
        }
        return $map;
    }

    /**
     * Order facets so that:
     *  1) fields listed in MeiliIndex::filterable appear first, in that exact order
     *  2) all remaining #[Facet]s follow in the class-declaration order
     *
     * @param array<string,array<string,mixed>> $facetMap keyed by attribute name (in insertion order)
     * @param string[] $filterable from MeiliIndex (already expanded)
     * @return array<string,array<string,mixed>> ordered associative array
     */
    private function orderFacets(array $facetMap, array $filterable): array
    {
        if ($facetMap === []) {
            return $facetMap;
        }

        // Keep the original insertion order from collectFacetMap() as "class order"
        $classOrder = array_keys($facetMap);

        // Priority: only those filterable fields that actually have a Facet config
        $priority = array_values(array_intersect($filterable, $classOrder));

        // Remaining facets in their original (class) order
        $remaining = array_values(array_diff($classOrder, $priority));

        // If Facet::order is set, sort within each group stably by 'order' then by original position
        $byOrder = static function (array $a, array $b) {
            $oa = $a['order'] ?? 0;
            $ob = $b['order'] ?? 0;
            return $oa <=> $ob;
        };

        if ($priority) {
            $tmp = [];
            foreach ($priority as $k) { $tmp[$k] = $facetMap[$k]; }
            uasort($tmp, $byOrder);
            $priority = array_keys($tmp);
        }
        if ($remaining) {
            $tmp = [];
            foreach ($remaining as $k) { $tmp[$k] = $facetMap[$k]; }
            uasort($tmp, $byOrder);
            $remaining = array_keys($tmp);
        }


        $orderedKeys = array_merge($priority, $remaining);
        $ordered = [];
        foreach ($orderedKeys as $k) {
            $ordered[$k] = $facetMap[$k];
        }
        return $ordered;
    }
    /**
     * Create a conservative default facet definition for a filterable field without #[Facet].
     * - Numerics → RangeSlider
     * - Arrays / JSON collections → RefinementList
     * - Everything else → RefinementList
     */
    private function defaultFacetConfig(\ReflectionClass $ref, string $field): array
    {
        $label = $this->humanize($field);
        $widget = 'RefinementList';
        $format = null;

        // Try to infer from property type if it exists
        $prop = $ref->hasProperty($field) ? $ref->getProperty($field) : null;
        if ($prop && $prop->hasType()) {
            $t = $prop->getType();
            $isBuiltin = $t instanceof \ReflectionNamedType && $t->isBuiltin();
            $name = $isBuiltin ? $t->getName() : ($t instanceof \ReflectionNamedType ? $t->getName() : null);

            if (in_array($name, ['int','float'])) {
                $widget = 'RangeSlider';
            } elseif ($name === 'array') {
                $widget = 'RefinementList';
            }
        }

        // Heuristics if no type: common numeric names → RangeSlider
        if ($widget === 'RefinementList') {
            if (preg_match('/(year|count|age|price|score|rating|runtime|length|index)$/i', $field)) {
                $widget = 'RangeSlider';
            }
        }

        return [
            'label'            => $label,
            'order'            => 0,
            'showMoreThreshold'=> 8,
            'widget'           => $widget,
            'type'             => $widget,   // soft-compat
            'format'           => $format,
            'visible'          => null,
            'tagsAny'          => [],
            'props'            => [],
            'sortMode'         => 'count',
            'collapsed'        => false,
            'limit'            => null,
            'showMoreLimit'    => null,
            'searchable'       => null,
            'lookup'           => [],
        ];
    }

    /** Simple labelizer: "phpVersions" → "Php Versions" */
    private function humanize(string $name): string
    {
        $s = preg_replace('/([a-z])([A-Z])/u', '$1 $2', $name);
        $s = str_replace('_', ' ', $s);
        return ucfirst($s);
    }


private function findIdentifierFields(string $entityClass): array
{
    $rc = new \ReflectionClass($entityClass);

    // EmbeddedId?
    foreach ($rc->getProperties() as $p) {
        if ($p->getAttributes(ORM\EmbeddedId::class)) {
            return [$p->getName()];
        }
    }

    // Regular @Id fields (composite supported)
    $ids = [];
    foreach ($rc->getProperties() as $p) {
        if ($p->getAttributes(ORM\Id::class)) {
            $ids[] = $p->getName();
        }
    }

    return $ids; // [] if not found (e.g., XML/YAML-mapped entities)
}

}
