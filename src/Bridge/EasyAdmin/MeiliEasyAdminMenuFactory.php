<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Bytes;
use function array_merge;
use function is_array;
use function sprintf;
use function str_replace;
use function strrpos;
use function substr;
use function ucfirst;
use function trim;
use function Symfony\Component\String\u;
use function Symfony\Component\Translation\t;

final class MeiliEasyAdminMenuFactory
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private MeiliEasyAdminDashboardHelper $dashboardHelper,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Top-level Meili index menus (one submenu per index).
     *
     * @return iterable<MenuItem>
     */
    public function createIndexMenus(string $routePrefix): iterable
    {
        foreach ($this->meiliService->settings as $indexName => $meiliSettings) {
            $label = $meiliSettings['label'] ?? $indexName;
            $entityClass = $meiliSettings['class'];
            $count = $this->meiliService->getApproxCount($entityClass);

            $subItems = $this->createIndexSubItems($indexName, $meiliSettings, $routePrefix);

            $icon = $meiliSettings['ui']['icon'] ?? 'database';
            $menuItem = MenuItem::subMenu($label, $icon)
                ->setBadge($count)
                ->setSubItems($subItems);
            yield $menuItem;
        }
    }

    /**
     * Sub-items for a single index, including semantic search links.
     *
     * @param array<string,mixed> $meiliSettings
     * @return MenuItem[]
     */
    private function createIndexSubItems(string $indexName, array $meiliSettings, string $routePrefix): array
    {
        $items = [];
        $routeEntity = $this->routeEntityName($meiliSettings);

        // Overview page inside admin (your showIndex route)

        $items[] = MenuItem::linkToRoute(
            t('action.detail', domain: 'EasyAdminBundle'),
            $this->dashboardHelper->getIcon('browse'),
            sprintf('%s_%s_index', $routePrefix, $routeEntity),
        );


        // Default instant search view (non-semantic)
        $sourceLocale = $this->sourceLocaleForBase($indexName);
        $routeParams = [
            'indexName' => $meiliSettings['prefixedName'] ?? $indexName,
        ];
        if ($sourceLocale !== null && trim($sourceLocale) !== '') {
            $routeParams['_locale'] = $sourceLocale;
        }

        $items[] = MenuItem::linkToRoute(
            t('action.search', domain: 'EasyAdminBundle'),
            $this->dashboardHelper->getIcon('instant_search'),
            'meili_insta',
            $routeParams
        )->setLinkTarget('_blank');

        // Semantic variations (this is your old getSemanticSearchItems logic)
        $items = array_merge(
            $items,
            $this->createSemanticSearchItems($indexName, $meiliSettings)
        );

        $items[] = MenuItem::linkToRoute(
            t('page_title.dashboard', domain: 'EasyAdminBundle'),
            $this->dashboardHelper->getIcon('action.detail'),
            $routePrefix . '_' . 'meili_index_dashboard',
            ['indexName' =>  $meiliSettings['prefixedName'] ?? $indexName,]
        );

        return $items;
    }

    /** @param array<string,mixed> $meiliSettings */
    private function routeEntityName(array $meiliSettings): string
    {
        $class = $meiliSettings['class'] ?? null;
        if (is_string($class) && $class !== '') {
            $short = substr($class, (int) (strrpos($class, '\\') ?: -1) + 1);
            return u($short)->snake()->toString();
        }

        $baseName = (string) ($meiliSettings['baseName'] ?? '');
        if ($baseName === '') {
            return '';
        }

        return u($baseName)->snake()->toString();
    }

    /**
     * Semantic search items for an index (one per embedder).
     *
     * @param array<string,mixed> $meiliSettings
     * @return MenuItem[]
     */
    public function createSemanticSearchItems(string $indexName, array $meiliSettings): array
    {
        $items = [];

        $sourceLocale = $this->sourceLocaleForBase($indexName);

        $embedders = $meiliSettings['embedders'] ?? [];
        if (!is_array($embedders) || !$embedders) {
            return $items;
        }

        foreach ($embedders as $embedder) {
            $params = [
                'indexName' => $indexName,
                'embedder'  => $embedder,
            ];
            if ($sourceLocale !== null && trim($sourceLocale) !== '') {
                $params['_locale'] = $sourceLocale;
            }

            $items[] = MenuItem::linkToUrl(
                'Semantic: ' . ucfirst(str_replace('_', ' ', (string) $embedder)),
                'semantic',
                $this->urlGenerator->generate('meili_insta_embed', $params)
            )->setLinkTarget('_blank');
        }

        return $items;
    }

    /**
     * Optional tools menu section from MeiliService->tools.
     *
     * @return iterable<MenuItem>
     */
    public function createToolsMenuItems(): iterable
    {
        // ??
        // riccox direct link?  meili?

//        foreach ($this->meiliService->tools as $tool) {
//            yield MenuItem::linkToUrl(
//                $tool['label'] ?? 'tool',
//                'tabler:chart-bar',
//                $tool['url'] ?? '#'
//            );
//        }
        return [];
    }

    private function sourceLocaleForBase(string $baseName): ?string
    {
        $loc = $this->meiliService->resolveLocalesForBase($baseName, 'en');
        return $loc['source'] ?? null;
    }
}
