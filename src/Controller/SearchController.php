<?php

namespace Survos\MeiliBundle\Controller;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[Route('/search')]
class SearchController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/js/')]  private string $jsTemplateDir,
        private readonly MeiliService $meiliService,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route('/index/{indexName}', name: 'meili_insta', options: ['expose' => true])]
    #[Route('/{_locale}/index/{indexName}', name: 'meili_insta_locale', options: ['expose' => true])]
    #[Route('/embedder/{indexName}/{embedder}', name: 'meili_insta_embed', options: ['expose' => true])]
    #[Template('@SurvosMeili/insta.html.twig')]
    public function index(
        Request $request,
        string $indexName,
        ?string $embedder = null,
        ?string $q = null,
        bool $useProxy = false,
    ): Response|array {
        // Treat route parameter as the *base* index name (compiler-pass key / settings key)
        $baseIndexName = $indexName;
        $locale        = $request->getLocale();

        // Resolve the actual Meilisearch UID once, based on bundle configuration
        if ($this->meiliService->isMultiLingual) {
            $meiliIndexUid = $this->meiliService->localizedUid($baseIndexName, $locale);
        } else {
            $meiliIndexUid = $baseIndexName;
        }

        // Meili endpoint is always referenced by UID
        $index = $this->meiliService->getIndexEndpoint($meiliIndexUid);
        try {
            $settings = $index->getSettings();
        } catch (\Exception $exception) {
            dd($exception, $meiliIndexUid, $this->meiliService->getMeiliClient());
        }

        // Index configuration comes from the *base* index settings
        $indexConfig = $this->meiliService->getIndexSetting($baseIndexName);
        assert($indexConfig, "Missing config for base index {$baseIndexName}");

        $templateName = $indexConfig['rawName'] ?? $baseIndexName;

        $sorting   = [];
        $sorting[] = ['label' => 'Relevance', 'value' => $meiliIndexUid];
        foreach (($settings['sortableAttributes'] ?? []) as $attr) {
            foreach (['asc', 'desc'] as $dir) {
                $sorting[] = [
                    'label' => sprintf('%s %s', $attr, $dir),
                    'value' => sprintf('%s:%s:%s', $meiliIndexUid, $attr, $dir),
                ];
            }
        }

        $stats = $index->stats();

        $params = [
            // Server / API key
            'server'      => $useProxy
                ? $this->router->generate('meili_proxy', [], UrlGeneratorInterface::ABSOLUTE_URL)
                : $this->meiliService->getHost(),
            'apiKey'      => $this->meiliService->getPublicApiKey(),

            // Index identifiers
            'indexName'     => $meiliIndexUid,  // actual Meili UID
            'baseIndexName' => $baseIndexName,  // base key for settings / config

            // Settings / config
            'indexConfig' => $indexConfig,
            'settings'    => $settings,
            'allSettings' => $this->meiliService->getAllSettings(),
            'primaryKey'  => $indexConfig['primaryKey'],

            // UI state
            'q'           => $q,
            'facets'      => $settings['filterableAttributes'] ?? [],
            'sorting'     => $sorting,
            'endpoint'    => null,
            'embedder'    => $embedder,
            'templateName'=> $templateName,
            'related'     => [],
            'indexStats'  => $stats,

            // Turn off type-as-you-type when an embedder is active
            'searchAsYouType' => $embedder === null,
        ];

        return $params;
    }

    #[AdminRoute(path: '/show/{indexName}/{pk}', name: 'meili_show_liquid')]
    public function showIndex(AdminContext $context, string $indexName): Response
    {
        return new Response();
    }
}
