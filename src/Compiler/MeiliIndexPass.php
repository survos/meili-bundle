<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Compiler;

use ApiPlatform\Metadata\ApiProperty;
use ReflectionClass;
use Survos\MeiliBundle\Metadata\Facet;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;
use function Symfony\Component\String\u;

final class MeiliIndexPass implements CompilerPassInterface
{
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

//                $x = $this->buildSettingsFor($fqcn);

                $ref = new ReflectionClass($fqcn);
                $facetMap = $this->collectFacetMap($ref);

                foreach ($ref->getAttributes(MeiliIndex::class) as $attr) {
                    /** @var MeiliIndex $cfg */
                    $cfg = $attr->newInstance();

                    $class = $cfg->class ?? $fqcn;
                    $name  = $cfg->name ?? $cfg->defaultName($class);
                    // can't add the prefix here, env not yet resolved
//                    $name = $container->getParameter('survos_meili.prefix') . $name;

                    // read via MeiliIndex accessors (order preserved)
                    $display    = $cfg->displayFields();
                    $filterable = $cfg->filterableFields();
                    $sortable   = $cfg->sortableFields();
                    $searchable = $cfg->searchableFields();

                    // ensure Facet-decorated fields are filterable
                    foreach (array_keys($facetMap) as $field) {
                        if (!in_array($field, $filterable, true)) {
                            $filterable[] = $field;
                        }
                    }

                    $indexEntities[$name] = $class;
                    // passed to meilisearch in /settings
                    $indexSchema = [
//                        'groups'     => $cfg->groups,
                        'displayedAttributes'    => $display,
                        'filterableAttributes' => $filterable,
                        'sortableAttributes'   => $sortable,
                        'searchableAttributes' => $searchable,
//                        'groups_by_setting' => [
//                            'display'    => $cfg->displayGroups(),
//                            'filterable' => $cfg->filterableGroups(),
//                            'sortable'   => $cfg->sortableGroups(),
//                            'searchable' => $cfg->searchableGroups(),
//                        ],
//                        'faceting'   => $cfg->faceting,
                        "faceting" => [
                            "sortFacetValuesBy" => ["*" => "count"],
                            "maxValuesPerFacet" => 1000, // $this->meiliService->getConfig()['maxValuesPerFacet']
                        ],
//                        'filter'     => $cfg->filter,
                    ];
                }
//                dd($cfg);
                //
                $indexSettings[$class][$name] = [
                    'schema' => $indexSchema,
                    'primaryKey' => $cfg->primaryKey,
//                    'groups' => $cfg->groups,
                    'persisted' => (array)$cfg->persisted,
                    'class' => $class,
                    'facets'     => $facetMap,
                    ];

            }
        }
//        dd($indexEntities, $indexSettings);

        $container->setParameter('meili.index_names', array_keys($indexEntities));
        $container->setParameter('meili.index_entities', $indexEntities);
        $container->setParameter('meili.index_settings', $indexSettings);

//        $container->setParameter('meili.indexed_entities', $indexedClasses);

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

    /**
     * Compute consolidated settings for one class by reflecting its attributes.
     *
     * Shape:
     * [
     *   'fields' => [
     *       'title' => ['sortable'=>true, 'searchable'=>true, 'browsable'=>false, 'is_primary'=>false],
     *       ...
     *   ],
     *   'normalization_groups' => ['group1', 'group2']
     * ]
     */
    private function buildSettingsFor(string $class): array
    {
        $rc = new \ReflectionClass($class);

        $settings = [
            'fields' => [],
            'normalization_groups' => $this->extractNormalizationGroups($rc),
        ];

        // 1) Class-level filters (#[ApiFilter])
        foreach ($rc->getAttributes() as $attribute) {
            if (!u($attribute->getName())->endsWith('ApiFilter')) {
                continue;
            }
            /** @var ApiFilter $apiFilter */
            $apiFilter = $attribute->newInstance();
            $filterClass = $apiFilter->filterClass ?? null;
            if (!$filterClass) {
                continue;
            }
            $properties = (array) ($apiFilter->properties ?? []);

            foreach ($properties as $prop => $typeOrNull) {
                // properties can be an indexed list or assoc array
                $field = \is_int($prop) ? (string) $typeOrNull : (string) $prop;

                $settings['fields'][$field] ??= [
                    'sortable' => false,
                    'searchable' => false,
                    'browsable' => false,
                    'is_primary' => false,
                ];

                switch ($filterClass) {
                    case OrderFilter::class:
                        $settings['fields'][$field]['sortable'] = true;
                        break;

                    case SearchFilter::class:
                        // If declared as "partial", mark searchable on that field
                        $decl = \is_int($prop) ? null : $typeOrNull;
                        if ($decl === 'partial' || $decl === null) {
                            $settings['fields'][$field]['searchable'] = true;
                        }
                        break;

                    case RangeFilter::class:
                        $settings['fields'][$field]['searchable'] = true;
                        break;

                    default:
                        // Facet-specific filters etc. can be added here if needed
                        break;
                }
            }
        }

        // 2) Property-level attributes
        foreach ($rc->getProperties() as $rp) {
            $field = $rp->getName();
            $settings['fields'][$field] ??= [
                'sortable' => false,
                'searchable' => false,
                'browsable' => false,
                'is_primary' => false,
            ];

            foreach ($rp->getAttributes() as $attr) {
                $name = $attr->getName();

                if (\in_array($name, [MeiliId::class, DoctrineId::class], true)) {
                    $settings['fields'][$field]['is_primary'] = true;
                }

                if ($name === ApiProperty::class) {
                    $args = $attr->getArguments();
                    if (($args['identifier'] ?? false) === true) {
                        $settings['fields'][$field]['is_primary'] = true;
                    }
                }

                if ($name === Facet::class) {
                    $settings['fields'][$field]['browsable'] = true;
                }

                if ($name === ApiFilter::class) {
                    /** @var ApiFilter $pf */
                    $pf = $attr->newInstance();
                    $filterClass = $pf->filterClass ?? null;

                    if ($filterClass === OrderFilter::class) {
                        $settings['fields'][$field]['sortable'] = true;
                    } elseif ($filterClass === SearchFilter::class || $filterClass === RangeFilter::class) {
                        $settings['fields'][$field]['searchable'] = true;
                    }
                }
            }
        }

        // 3) Method-level Facet attributes (e.g., accessor-derived facets)
        foreach ($rc->getMethods() as $rm) {
            $field = $rm->getName();
            foreach ($rm->getAttributes() as $attr) {
                if ($attr->getName() === Facet::class) {
                    $settings['fields'][$field] ??= [
                        'sortable' => false,
                        'searchable' => false,
                        'browsable' => false,
                        'is_primary' => false,
                    ];
                    $settings['fields'][$field]['browsable'] = true;
                }
            }
        }

        return $settings;
    }

    /** Pull normalization groups from #[ApiResource] at class level. */
    private function extractNormalizationGroups(\ReflectionClass $rc): ?array
    {
        $groups = null;

        foreach ($rc->getAttributes(ApiResource::class) as $attr) {
            /** @var ApiResource $api */
            $api = $attr->newInstance();
            $ctx = $api->normalizationContext ?? null;
            if (\is_array($ctx) && \array_key_exists('groups', $ctx)) {
                $g = $ctx['groups'];
                $groups = \is_string($g) ? [$g] : (is_array($g) ? array_values($g) : null);
            }
        }
        return $groups;
    }

    /** Convert a file path under src/ to an FQCN. */
    private static function classFromPath(string $srcDir, string $path): string
    {
        $rel = ltrim(str_replace($srcDir, '', $path), DIRECTORY_SEPARATOR);
        $rel = preg_replace('/\.php$/', '', $rel) ?? $rel;
        $parts = array_map(
            static fn($p) => $p === '' ? $p : str_replace('/', '\\', $p),
            [trim($rel, '/')]
        );
        return 'App\\' . str_replace('/', '\\', $parts[0]);
    }
}
