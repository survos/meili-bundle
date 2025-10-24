<?php

namespace Survos\MeiliBundle\Controller;

use cebe\openapi\Reader;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

#[Route('/instant-search')]
class SearchController extends AbstractController
{
    protected $helper;

    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/js/')]
        private string                   $jsTemplateDir,
        private readonly MeiliService    $meiliService,
        private readonly RouterInterface $router,
    )
    {
//        $this->helper = $helper;
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

        // Meili settings (single source of truth)
        $index = $this->meiliService->getIndexEndpoint($indexName);
        $settings = $index->getSettings();

        // Template name derived from rawName (without prefix)
        $raw = $this->meiliService->getIndexSetting($indexName);
        $templateName = $raw['rawName'] ?? $indexName;

        // Build sort options from sortableAttributes
        $sorting = [];
        $sorting[] = [
            'label' => 'Relevance',
            'value' => $indexName, // default mode
        ];

        foreach (($settings['sortableAttributes'] ?? []) as $attr) {
            foreach (['asc', 'desc'] as $dir) {
                $sorting[] = [
                    'label' => sprintf('%s %s', $attr, $dir),
                    // instant-meilisearch understands indexUid:field:order here
                    'value' => sprintf('%s:%s:%s', $indexName, $attr, $dir),
                ];
            }
        }

        $indexConfig = $this->meiliService->getIndexSetting($indexName);
        $params = [
            'server' => $useProxy
                ? $this->router->generate('meili_proxy', [], UrlGeneratorInterface::ABSOLUTE_URL)
                : $this->meiliService->getHost(),

            'apiKey'       => $this->meiliService->getPublicApiKey(),
            'indexName'    => $indexName,
            'indexConfig'  => $indexConfig,
            'settings'     => $settings,
            'primaryKey'   => $indexConfig['primaryKey'],
            'q'            => $q,
            'facets'       => $settings['filterableAttributes'] ?? [],
            'sorting'      => $sorting,
            'endpoint'     => null,
            'embedder'     => $embedder,
            'templateName' => $templateName,
            'related'      => [],
        ];

        return $params;
    }

    // hack function until we can figure out relative routing for jstwig
    #[Route('/template/{templateName}', name: 'meili_template')]
    public function jsTemplate(string $templateName): Response|array
    {
        // remove the locale, ugh.
        $templateName = preg_replace('/_..$/', '', $templateName);
//        dd($indexName);
        $jsTwigTemplate = $this->jsTemplateDir . $templateName . '.html.twig';
        if (!file_exists($jsTwigTemplate)) {
            return new Response("Missing $jwTwigTemplate template");
        }
        assert(file_exists($jsTwigTemplate), "missing $jsTwigTemplate");
        $template = file_get_contents($jsTwigTemplate);
//        dd($template);
        return new Response($template);
    }

    #[AdminRoute(path: '/show/{indexName}/{pk}', name: 'meili_show_liquid')]
    public function showIndex(
        AdminContext $context,
        string $indexName,
    ): Response
    {
        $embedders = $this->meiliService->getConfig()['embedders'];
        $indexSettings = $this->meiliService->getRawIndexSetting($indexName);

        $totalTokens = [];
        foreach ($embedders as $index => $embedder) {
            $template = new \Liquid\Template();
            $template->parse(file_get_contents($embedder['template']));
            $templates[$index] = $template;
        }
        $embedderKeys = $settings['embedders'] ?? [];
        // serialize the doc first

        $iterator = $this->entityManager->getRepository($settings['class'])->createQueryBuilder('e')->select('e')
            ->setMaxResults(3)
            ->getQuery()
            ->toIterable();
        foreach ($iterator as $e) {
            // chicken and egg -- we want to get the data from meili, it's exact, but we don't want to add it if the embedder is active.
            $data = $this->payloadBuilder->build($e, $settings['persisted']);
            dump($data);
            foreach ($embedderKeys as $embedderKey) {
                $text = $templates[$embedderKey]->render(['doc' => $data]);
                dd($embedderKey, $text);
            }
        }
        dd($embedderKeys);

        // configured
        $settings = $this->meiliService->settings[$indexName];
        // live
        $index = $this->meiliService->getIndex($indexName, autoCreate: false);

        return new Response();

    }



    }
