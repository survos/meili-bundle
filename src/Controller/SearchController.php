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
        #[Autowire('%kernel.project_dir%/templates/js/')] private string $jsTemplateDir,
        private readonly MeiliService $meiliService,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route('/index/{indexName}', name: 'meili_insta', options: ['expose' => true])]
    #[Route('/index/{indexName}', name: 'meili_insta_locale', options: ['expose' => true])]
    #[Route('/embedder/{indexName}/{embedder}', name: 'meili_insta_embed', options: ['expose' => true])]
    #[Template('@SurvosMeili/insta.html.twig')]
    public function index(
        Request $request,
        string $indexName,
        ?string $embedder = null,
        ?string $q = null,
        bool $useProxy = false,
    ): Response|array {
        // Route parameter is the BASE name (registry key)
        $baseIndexName = $indexName;
        $locale = $request->getLocale();

        // Resolve the actual Meilisearch UID using the new naming resolver logic
        $meiliIndexUid = $this->meiliService->uidForBase($baseIndexName, $locale);

        // Index configuration is base-keyed
        $indexConfig = $this->meiliService->getIndexSetting($baseIndexName);
        assert($indexConfig, "Missing config for base index {$baseIndexName}");

        // Locale-agnostic template selection
        $templateName = $indexConfig['template'] ?? $baseIndexName;

        // Fetch server settings using the UID
        $index = $this->meiliService->getIndexEndpoint($meiliIndexUid);
        try {
            $settings = $index->getSettings();
        } catch (\Throwable $exception) {
            // useful when index isn't created yet / wrong uid
            throw $exception;
        }

        $sorting = [];
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

        return [
            // Server / API key
            'server' => $useProxy
                ? $this->router->generate('meili_proxy', [], UrlGeneratorInterface::ABSOLUTE_URL)
                : $this->meiliService->getHost(),
            'apiKey'  => $this->meiliService->getPublicApiKey(),

            // Index identifiers
            'indexName'     => $meiliIndexUid,  // actual Meili UID (what JS uses)
            'baseIndexName' => $baseIndexName,  // base key (registry)

            // Settings / config
            'indexConfig' => $indexConfig,
            'settings'    => $settings,
            'allSettings' => $this->meiliService->getAllSettings(),
            'primaryKey'  => $indexConfig['primaryKey'] ?? 'id',

            // UI state
            'q'              => $q,
            'facets'         => $settings['filterableAttributes'] ?? [],
            'sorting'        => $sorting,
            'endpoint'       => null,
            'embedder'       => $embedder,
            'templateName'   => $templateName,
            'related'        => [],
            'indexStats'     => $stats,
            'multiLingual'   => $this->meiliService->isMultiLingual,
            'translationStyle' => $this->meiliService->getConfig()['translationStyle'] ?? null,

            // Turn off type-as-you-type when an embedder is active
            'searchAsYouType' => $embedder === null,
        ];
    }

    #[AdminRoute(path: '/show/liquid/{indexName}', name: 'meili_show_liquid')]
    public function showLiquid(AdminContext $context, string $indexName): Response
    {
        return new Response();
    }
}
