<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Compiler;

use Doctrine\ORM\Mapping as ORM;
use ReflectionClass;
use Survos\MeiliBundle\Metadata\Facet;
use Survos\MeiliBundle\Metadata\FacetWidget;
use Survos\MeiliBundle\Metadata\InstaView;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Util\GroupFieldResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;

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
            ? $container->getParameter('survos_meili.entity_dirs')
            : []);

        // Default: scan Doctrine entities AND index DTOs
        if ($scanDirs === []) {
            $scanDirs = [
                (string) $container->getParameter('kernel.project_dir') . '/src/Entity',
                (string) $container->getParameter('kernel.project_dir') . '/src/Index',
            ];
        }

        $bag = $container->getParameterBag();
        $scanDirs = array_map(static fn($p) => (string) $bag->resolveValue($p), $scanDirs);

        $indexEntities = [];
        $indexSettings = [];

        foreach ($scanDirs as $dir) {
            foreach ($this->classesIn($dir) as $fqcn) {
                if (!class_exists($fqcn)) {
                    continue;
                }

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

                    // Add any #[Facet] fields to filterable (so UI config implies filterability)
                    foreach (array_keys($facetMap) as $field) {
                        if (!in_array($field, $filterable, true)) {
                            $filterable[] = $field;
                        }
                    }

                    // Strict field checking (only if persisted list is explicit and strict enabled)
                    $strict = (bool) ($container->hasParameter('survos_meili.strict')
                        ? $container->getParameter('survos_meili.strict')
                        : false);

                    if ($persisted !== [] && $strict) {
                        $toCheck = [
                            'filterable' => $filterable,
                            'sortable'   => $sortable,
                            'searchable' => ($searchable === ['*']) ? [] : $searchable,
                        ];

                        foreach ($toCheck as $label => $fields) {
                            foreach ($fields as $f) {
                                if ($f === '*') {
                                    continue;
                                }
                                if (!in_array($f, $persisted, true)) {
                                    throw new \InvalidArgumentException(sprintf(
                                        '[Survos/Meili] %s field "%s" is not in persisted for index "%s" (%s); it will not be effective.',
                                        $label,
                                        $f,
                                        $name,
                                        $class
                                    ));
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

                    // InstaView hints (optional)
                    $instaViewHints = [];
                    foreach ($ref->getAttributes(InstaView::class) as $ivAttr) {
                        /** @var InstaView $iv */
                        $iv = $ivAttr->newInstance();
                        $instaViewHints = array_filter([
                            'hitClass'  => $iv->hitClass,
                            'template'  => $iv->template,
                            'showStats' => $iv->showStats,
                        ], static fn($v) => $v !== null);
                        break;
                    }

                    // Ensure every filterable has a facet config (defaults when not annotated)
                    foreach ($filterable as $field) {
                        if (!isset($facetMap[$field])) {
                            $facetMap[$field] = $this->defaultFacetConfig($ref, $field);
                        }
                    }

                    // Order facets: MeiliIndex::filterable order, then class order
                    $facetMap = $this->orderFacets($facetMap, $filterable);

                    // Validate: no facet config for non-filterable field
                    foreach (array_keys($facetMap) as $field) {
                        if (!in_array($field, $filterable, true)) {
                            throw new \InvalidArgumentException(sprintf(
                                '[Survos/Meili] Facet "%s" is not listed in MeiliIndex::filterable for "%s".',
                                $field,
                                $name
                            ));
                        }
                    }

                    // Primary key: optional in attribute, infer if missing.
                    if (!$cfg->primaryKey) {
                        $cfg->primaryKey = $this->inferPrimaryKey($ref);
                        if (!$cfg->primaryKey) {
                            throw new \RuntimeException(sprintf(
                                '[Survos/Meili] %s must declare primaryKey in #[MeiliIndex] (no id/code/key property and not a Doctrine entity with #[ORM\\Id]).',
                                $class
                            ));
                        }
                    }

                    $indexSettings[$class][$name] = [
                        'schema'      => $indexSchema,
                        'primaryKey'  => $cfg->primaryKey,
                        'persisted'   => (array) $cfg->persisted,
                        'class'       => $class,
                        'facets'      => $facetMap,
                        'embedders'   => $cfg->embedders,
                        'autoIndex'   => $cfg->autoIndex,
                        'ui'          => $cfg->ui,
                        'instaView'   => $instaViewHints,
                    ];
                }
            }
        }

        // Merge with any previously-registered indexes (e.g. Pixie)
        $prevEntities = $container->hasParameter('meili.index_entities')
            ? (array) $container->getParameter('meili.index_entities')
            : [];

        $prevSettings = $container->hasParameter('meili.index_settings')
            ? (array) $container->getParameter('meili.index_settings')
            : [];

        $prevNames = $container->hasParameter('meili.index_names')
            ? (array) $container->getParameter('meili.index_names')
            : [];

        $mergedEntities = array_replace($prevEntities, $indexEntities);
        $mergedSettings = array_replace_recursive($prevSettings, $indexSettings);

        $mergedNames = array_values(array_unique(array_merge($prevNames, array_keys($mergedEntities))));
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
        if (!is_dir($dir)) {
            return;
        }

        $finder = (new Finder())->files()->in($dir)->name('*.php');

        foreach ($finder as $file) {
            $src = @file_get_contents($file->getPathname());
            if ($src === false) {
                continue;
            }

            if (!preg_match('/namespace\s+([^;]+);/m', $src, $ns)) {
                continue;
            }

            // IMPORTANT: supports "final class", "abstract class"
            if (!preg_match('/\b(?:final\s+|abstract\s+)?class\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\b/m', $src, $cls)) {
                continue;
            }

            yield trim($ns[1]) . '\\' . trim($cls[1]);
        }
    }

    private function collectFacetMap(ReflectionClass $ref): array
    {
        $map = [];

        $collect = static function (Facet $f, string $name): array {
            $widget = $f->widget;
            if ($widget instanceof FacetWidget) {
                $widget = $widget->value;
            }

            return [
                'label'             => $f->label ?? $name,
                'order'             => $f->order,
                'showMoreThreshold' => $f->showMoreThreshold,
                'widget'            => $widget,
                'type'              => $widget, // soft-compat
                'format'            => $f->format,
                'visible'           => $f->visible,
                'tagsAny'           => $f->tagsAny,
                'props'             => $f->props,
                'sortMode'          => $f->sortMode,
                'collapsed'         => $f->collapsed,
                'limit'             => $f->limit,
                'showMoreLimit'     => $f->showMoreLimit,
                'searchable'        => $f->searchable,
                'lookup'            => $f->lookup,
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
     *  2) remaining facets follow in class-declaration order
     *
     * @param array<string,array<string,mixed>> $facetMap
     * @param string[] $filterable
     * @return array<string,array<string,mixed>>
     */
    private function orderFacets(array $facetMap, array $filterable): array
    {
        if ($facetMap === []) {
            return $facetMap;
        }

        $classOrder = array_keys($facetMap);
        $priority   = array_values(array_intersect($filterable, $classOrder));
        $remaining  = array_values(array_diff($classOrder, $priority));

        $byOrder = static function (array $a, array $b): int {
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
     * Create a default facet definition for a filterable field without #[Facet].
     */
    private function defaultFacetConfig(ReflectionClass $ref, string $field): array
    {
        $label  = $this->humanize($field);
        $widget = 'RefinementList';
        $format = null;

        $prop = $ref->hasProperty($field) ? $ref->getProperty($field) : null;
        if ($prop && $prop->hasType()) {
            $t = $prop->getType();
            if ($t instanceof \ReflectionNamedType && $t->isBuiltin()) {
                $name = $t->getName();
                if (in_array($name, ['int','float'], true)) {
                    $widget = 'RangeSlider';
                } elseif ($name === 'array') {
                    $widget = 'RefinementList';
                }
            }
        }

        if ($widget === 'RefinementList') {
            if (preg_match('/(year|count|age|price|score|rating|runtime|length|index)$/i', $field)) {
                $widget = 'RangeSlider';
            }
        }

        return [
            'label'             => $label,
            'order'             => 0,
            'showMoreThreshold' => 8,
            'widget'            => $widget,
            'type'              => $widget, // soft-compat
            'format'            => $format,
            'visible'           => null,
            'tagsAny'           => [],
            'props'             => [],
            'sortMode'          => 'count',
            'collapsed'         => false,
            'limit'             => null,
            'showMoreLimit'     => null,
            'searchable'        => null,
            'lookup'            => [],
        ];
    }

    private function humanize(string $name): string
    {
        $s = preg_replace('/([a-z])([A-Z])/u', '$1 $2', $name);
        $s = str_replace('_', ' ', (string) $s);
        return ucfirst((string) $s);
    }

    /**
     * Infer primary key:
     *  - prefer conventional property names for index DTOs: id, code, key
     *  - if Doctrine entity, infer #[ORM\Id] (single id only)
     */
    private function inferPrimaryKey(ReflectionClass $ref): ?string
    {
        foreach (['id', 'code', 'key'] as $cand) {
            if ($ref->hasProperty($cand)) {
                return $cand;
            }
        }

        // Doctrine entity? look for #[ORM\Id]
        if ($this->isDoctrineEntity($ref)) {
            $ids = $this->findIdentifierFields($ref->getName());
            if (count($ids) === 1) {
                return $ids[0];
            }
        }

        return null;
    }

    private function isDoctrineEntity(ReflectionClass $ref): bool
    {
        return (bool) $ref->getAttributes(ORM\Entity::class)
            || (bool) $ref->getAttributes(ORM\MappedSuperclass::class)
            || (bool) $ref->getAttributes(ORM\Embeddable::class);
    }

    private function findIdentifierFields(string $entityClass): array
    {
        $rc = new ReflectionClass($entityClass);

        foreach ($rc->getProperties() as $p) {
            if ($p->getAttributes(ORM\EmbeddedId::class)) {
                return [$p->getName()];
            }
        }

        $ids = [];
        foreach ($rc->getProperties() as $p) {
            if ($p->getAttributes(ORM\Id::class)) {
                $ids[] = $p->getName();
            }
        }

        return $ids;
    }
}
