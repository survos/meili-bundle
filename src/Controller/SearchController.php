<?php

namespace Survos\MeiliBundle\Controller;

use cebe\openapi\Reader;
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
    #[Template('@SurvosMeili/insta.html.twig')]
    public function index(
        Request $request, // for the locale, but we will need a better way!
        string                       $indexName, //  = 'packagesPackage',
        #[MapQueryParameter] ?string $embedder = null,
        #[MapQueryParameter] bool    $useProxy = false,
    ): Response|array
    {
        $locale = $request->getLocale();
        $template = $indexName . '.html.twig';

        if ($this->meiliService->passLocale) {
            $indexName .= "_$locale";
        }
        if (0) {
            $dummyServer = 'https://dummy.survos.com/api/docs.jsonopenapi';
// realpath is needed for resolving references with relative Paths or URLs
            $openapi = Reader::readFromJsonFile($dummyServer);
            $openapi->resolveReferences();
        }

        // @todo: starting only
        $settings = $this->meiliService->getIndexSetting($indexName);
        $templateName = $settings['rawName']; // skip the prefix

        // Entity, then _list_ of groups separated by _
//        dd($openapi->components->schemas['Product.jsonld-product.read_product.details']);


//        dd($openapi);
//        if (!class_exists($indexName) && class_exists($appEntityClass = 'App\\Entity\\' . $indexName)) {
//            $indexName = $appEntityClass;
//        }
//
//        if (class_exists($indexName)) {
//            $indexName = $this->meiliService->getPrefixedIndexName($indexName);
//        }

        $locale = 'en'; // @todo
        $index = $this->meiliService->getIndexEndpoint($indexName);
        $settings = $index->getSettings();
        $sorting[] = ['value' => $indexName, 'label' => 'relevancy'];
        foreach ($settings['sortableAttributes'] as $sortableAttribute) {
            foreach (['asc', 'desc'] as $direction) {
                $sorting[] = [
                    'label' => sprintf("%s %s", $sortableAttribute, $direction),
                    'value' => sprintf("%s:%s:%s", $indexName, $sortableAttribute, $direction)
                ];
            }
        }
        $facets = $settings['filterableAttributes'];

        // this is specific to our way of handling related, translated messages, soon to be removed.
        $related = []; // $this->meiliService->getRelated($facets, $indexName, $locale);
        // use proxy for translations or hidden
        $params = [
            'server' =>
                $useProxy
                    ? $this->router->generate('meili_proxy', [],
                    UrlGeneratorInterface::ABSOLUTE_URL)
                    : $this->meiliService->getHost(),

            'apiKey' => $this->meiliService->getPublicApiKey(),
            'indexName' => $indexName,
            'facets' => $facets,
            'sorting' => $sorting,
            'settings' => $settings,
            'endpoint' => null,
            'embedder' => $embedder,
            'templateName' => $templateName,
            'related' => $related, // the facet lookups
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


}
