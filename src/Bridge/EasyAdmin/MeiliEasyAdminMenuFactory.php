<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Bridge\EasyAdmin;

use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MeiliEasyAdminMenuFactory
{
    public function __construct(
        private MeiliService $meiliService,
        private EntityManagerInterface $em,
        private LocaleContext $localeContext,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Build EasyAdmin menu items for all Meili indices known to MeiliService.
     *
     * @return iterable<MenuItem>
     */
    public function createIndexMenus(): iterable
    {
        // Support either public property or getter, depending on how MeiliService is implemented.
        $settings = $this->meiliService->settings;
        foreach ($settings as $indexName => $meiliSetting) {
            if (!isset($meiliSetting['class']) || !\is_string($meiliSetting['class'])) {
                continue;
            }

            $class = $meiliSetting['class'];

            if (!class_exists($class)) {
                continue;
            }

            $label = (new \ReflectionClass($class))->getShortName();
            $count = $this->em->getRepository($class)->count([]);

            yield MenuItem::subMenu($label, 'fas fa-film')
                ->setBadge($count, 'info')
                ->setSubItems([
                    // CRUD management
                    MenuItem::linkToCrud('Browse All', 'fas fa-table', $class),

                    // Facet / Meili settings view
                    MenuItem::linkToUrl(
                        'Facet Details',
                        'fas fa-search',
                        $this->urlGenerator->generate('meili_admin_meili_show_index', [
                            'indexName' => $indexName,
                        ])
                    ),

                    // Divider before search links
                    MenuItem::section('Search Options'),

                    // Full-text search (classic insta)
                    MenuItem::linkToUrl(
                        'Full-Text Search',
                        'fas fa-search',
                        $this->urlGenerator->generate('meili_insta', [
                            'indexName' => $indexName,
                        ])
                    )->setLinkTarget('_blank'),


                    // Semantic search variants
                    ...$this->getSemanticSearchItems($indexName, $meiliSetting),
                ]);

            if ($this->meiliService->isMultiLingual)
            {
                foreach ($this->localeContext->getEnabled() as $locale) {
                    yield MenuItem::linkToUrl(
                        'Full-Text Search ' . $locale,
                        'fas fa-search',
                        $this->urlGenerator->generate('meili_insta_locale', [
                            '_locale' => $locale,
                            'indexName' => $indexName,
                        ])
                    )->setLinkTarget('_blank');
                }
            }


        }
    }

    /**
     * Build “Semantic” search items per embedder.
     *
     * @return MenuItem[]
     */
    private function getSemanticSearchItems(string $indexName, array $meiliSetting): array
    {
        $items = [];

        if (!empty($meiliSetting['embedders']) && \is_array($meiliSetting['embedders'])) {
            foreach ($meiliSetting['embedders'] as $embedder) {
                $label = 'Semantic: ' . ucfirst(str_replace('_', ' ', (string) $embedder));

                $items[] = MenuItem::linkToUrl(
                    $label,
                    'fas fa-brain',
                    $this->urlGenerator->generate('meili_insta_embed', [
                        'indexName' => $indexName,
                        'embedder'  => $embedder,
                    ])
                )->setLinkTarget('_blank');
            }
        }

        return $items;
    }
}
