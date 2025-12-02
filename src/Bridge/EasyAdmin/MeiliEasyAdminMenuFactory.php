<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MeiliEasyAdminMenuFactory
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Top-level Meili index menus (one submenu per index).
     *
     * @return iterable<MenuItem>
     */
    public function createIndexMenus(): iterable
    {
        foreach ($this->meiliService->settings as $indexName => $meiliSettings) {
            $label = $meiliSettings['label'] ?? $indexName;

            $subItems = $this->createIndexSubItems($indexName, $meiliSettings);

            yield MenuItem::subMenu($label, 'fas fa-database')
                ->setSubItems($subItems);
        }
    }

    /**
     * Sub-items for a single index, including semantic search links.
     *
     * @param array<string,mixed> $meiliSettings
     * @return MenuItem[]
     */
    private function createIndexSubItems(string $indexName, array $meiliSettings): array
    {
        $items = [];

        // Overview page inside admin (your showIndex route)
        $items[] = MenuItem::linkToRoute(
            'overview',
            'fas fa-eye',
            'meili_show_index',
            ['indexName' => $indexName]
        );

        // Default instant search view (non-semantic)
        $items[] = MenuItem::linkToRoute(
            'instant_search',
            'fas fa-search',
            'meili_insta',
            [
                'indexName' => $meiliSettings['prefixedName'] ?? $indexName,
            ]
        );

        // Semantic variations (this is your old getSemanticSearchItems logic)
        $items = array_merge(
            $items,
            $this->createSemanticSearchItems($indexName, $meiliSettings)
        );

        return $items;
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

        $embedders = $meiliSettings['embedders'] ?? [];
        if (!is_array($embedders) || !$embedders) {
            return $items;
        }

        foreach ($embedders as $embedder) {
            $items[] = MenuItem::linkToUrl(
                'Semantic: ' . ucfirst(str_replace('_', ' ', (string) $embedder)),
                'fas fa-brain',
                $this->urlGenerator->generate('meili_insta_embed', [
                    'indexName' => $indexName,
                    'embedder'  => $embedder,
                ])
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
        foreach ($this->meiliService->tools as $tool) {
            yield MenuItem::linkToUrl(
                $tool['label'] ?? 'tool',
                'fas fa-chart-line',
                $tool['url'] ?? '#'
            );
        }
    }
}
