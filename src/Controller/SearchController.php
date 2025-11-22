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
    protected $helper;

    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/js/')]  private string $jsTemplateDir,
        private readonly MeiliService $meiliService,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route('/index/{indexName}', name: 'meili_insta', options: ['expose' => true])]
    #[Route('/embedder/{indexName}/{embedder}', name: 'meili_insta_embed', options: ['expose' => true])]
    #[Template('@SurvosMeili/insta.html.twig')]
    public function index(
        Request $request,
        string $indexName,
        ?string $embedder = null,
        ?string $q = null,
        bool $useProxy = false,
    ): Response|array {
        $locale = $request->getLocale();

        if ($this->meiliService->passLocale) {
            $indexName .= "_$locale";
        }

        $index    = $this->meiliService->getIndexEndpoint($indexName);
        try {
            $settings = $index->getSettings();
        } catch (\Exception $exception) {
            dd($exception, $indexName, $this->meiliService->getMeiliClient());
        }

        $raw          = $this->meiliService->getIndexSetting($indexName);
        $templateName = $raw['rawName'] ?? $indexName;

        $sorting = [];
        $sorting[] = ['label' => 'Relevance', 'value' => $indexName];
        foreach (($settings['sortableAttributes'] ?? []) as $attr) {
            foreach (['asc', 'desc'] as $dir) {
                $sorting[] = [
                    'label' => sprintf('%s %s', $attr, $dir),
                    'value' => sprintf('%s:%s:%s', $indexName, $attr, $dir),
                ];
            }
        }

        $indexConfig = $this->meiliService->getIndexSetting($indexName);
        assert($indexConfig, "Missing config for $indexName");
        $stats = $index->stats();
//        $results = $index->search(null, [
//            'facets' => array_keys($indexConfig['facets'] ?? []),
//        ]);

        $params = [
            'server'           => $useProxy
                ? $this->router->generate('meili_proxy', [], UrlGeneratorInterface::ABSOLUTE_URL)
                : $this->meiliService->getHost(),
            'apiKey'           => $this->meiliService->getPublicApiKey(),
            'indexName'        => $indexName,
            'indexConfig'      => $indexConfig,
            'settings'         => $settings,
            'allSettings'      => $this->meiliService->getAllSettings(),
            'primaryKey'       => $indexConfig['primaryKey'],
            'q'                => $q,
            'facets'           => $settings['filterableAttributes'] ?? [],
            'sorting'          => $sorting,
            'endpoint'         => null,
            'embedder'         => $embedder,
            'templateName'     => $templateName,
            'related'          => [],
            'indexStats' => $stats,
            // NEW: turn off type-as-you-type when an embedder is active
            'searchAsYouType'  => $embedder === null,
        ];

        return $params;
    }

//    #[Route('/old/template/{templateName}', name: 'OLD_meili_template')]
//    public function jsTemplate(string $templateName): Response|array
//    {
//        $templateName = preg_replace('/_..$/', '', $templateName);
//        $jsTwigTemplate = $this->jsTemplateDir . $templateName . '.html.twig';
//        if (!file_exists($jsTwigTemplate)) {
//            // fix: correct variable name in message
//            return new Response("Missing $jsTwigTemplate template", 404);
//        }
//        $template = file_get_contents($jsTwigTemplate);
//        return new Response($template);
//    }

    #[AdminRoute(path: '/show/{indexName}/{pk}', name: 'meili_show_liquid')]
    public function showIndex(AdminContext $context, string $indexName): Response
    {
        return new Response();
    }
}
