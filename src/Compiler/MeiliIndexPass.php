<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Compiler;

use ReflectionClass;
use Survos\MeiliBundle\Metadata\Facet;
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

                    // Union: explicit fields ⊔ group-derived fields (null-safe)
                    $display    = $this->groupResolver->expandUnion($class, $cfg->displayFields(),    $cfg->displayGroups());
                    $filterable = $this->groupResolver->expandUnion($class, $cfg->filterableFields(), $cfg->filterableGroups());
                    $sortable   = $this->groupResolver->expandUnion($class, $cfg->sortableFields(),   $cfg->sortableGroups());
                    $searchable = $this->groupResolver->expandUnion($class, $cfg->searchableFields(), $cfg->searchableGroups());

                    // ADD: expand persisted (union of explicit fields + groups)
                    $persistedFields = $cfg->persistedFields();
                    $persistedGroups = $cfg->persistedGroups();
                    $persisted       = $this->groupResolver->expandUnion($class, $persistedFields, $persistedGroups);


                    // Ensure Facet-decorated fields are filterable
                    foreach (array_keys($facetMap) as $field) {
                        if (!in_array($field, $filterable, true)) {
                            $filterable[] = $field;
                        }
                    }

                    // Validate that filterable/sortable/searchable fields exist in persisted
//                    $persisted = array_values(array_unique(array_filter((array) $cfg->persisted)));
                    if ($persisted !== []) { // only validate if user constrained persisted
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
                                    dd($persisted, $f);
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
                        'displayedAttributes'  => $display ?: ['*'], // sensible default for display
                        'filterableAttributes' => array_values(array_unique($filterable)),
                        'sortableAttributes'   => array_values(array_unique($sortable)),
                        'searchableAttributes' => $searchable ?: ['*'], // default searchable to ['*']
                        'faceting' => [
                            'sortFacetValuesBy' => ['*' => 'count'],
                            'maxValuesPerFacet' => 1000,
                        ],
                    ];

                    $indexSettings[$class][$name] = [
                        'schema'     => $indexSchema,
                        'primaryKey' => $cfg->primaryKey,
                        'persisted'  => (array) $cfg->persisted,
                        'class'      => $class,
                        'facets'     => $facetMap,
                    ];
                }
            }
        }

        $container->setParameter('meili.index_names', array_keys($indexEntities));
        $container->setParameter('meili.index_entities', $indexEntities);
        $container->setParameter('meili.index_settings', $indexSettings);

        if ($container->hasDefinition(MeiliService::class)) {
            $def = $container->getDefinition(MeiliService::class);
            $def->setArgument('$indexedEntities', $indexEntities);
            $def->setArgument('$indexSettings', $indexSettings);
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

        foreach ($ref->getProperties() as $p) {
            foreach ($p->getAttributes(Facet::class) as $a) {
                /** @var Facet $f */
                $f = $a->newInstance();
                $name = $p->getName();
                $map[$name] = [
                    'label' => $f->label ?? $name,
                    'order' => $f->order,
                    'showMoreThreshold' => $f->showMoreThreshold,
                    'type' => $f->type,
                    'format' => $f->format,
                    'visible' => $f->visible,
                    'tagsAny' => $f->tagsAny,
                    'props' => $f->props,
                ];
            }
        }
        foreach ($ref->getMethods() as $m) {
            foreach ($m->getAttributes(Facet::class) as $a) {
                /** @var Facet $f */
                $f = $a->newInstance();
                $name = $m->getName();
                $map[$name] = [
                    'label' => $f->label ?? $name,
                    'order' => $f->order,
                    'showMoreThreshold' => $f->showMoreThreshold,
                    'type' => $f->type,
                    'format' => $f->format,
                    'visible' => $f->visible,
                    'tagsAny' => $f->tagsAny,
                    'props' => $f->props,
                ];
            }
        }
        return $map;
    }
}
