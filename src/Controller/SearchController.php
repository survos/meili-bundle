<?php

namespace Survos\MeiliBundle\Controller;

use cebe\openapi\Reader;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

#[Route('/instant-search')]
class SearchController extends AbstractController
{
    protected $helper;

    public function __construct(
        private MeiliService  $meili,
    )
    {
//        $this->helper = $helper;
    }
    #[Route('/index/{indexName}', name: 'app_insta')]
    #[Template('@SurvosMeili/insta.html.twig')]
    public function index(
        string $indexName, //  = 'packagesPackage',
        #[MapQueryParameter] ?string $embedder = null,
        #[MapQueryParameter] bool $useProxy = false,
    ): Response|array
    {
        $endpoint = $this->endpointRepository->findOneBy([
                'name' => $indexName]
        );

        if (0) {
            $dummyServer = 'https://dummy.survos.com/api/docs.jsonopenapi';
// realpath is needed for resolving references with relative Paths or URLs
            $openapi = Reader::readFromJsonFile($dummyServer);
            $openapi->resolveReferences();
        }

        // Entity, then _list_ of groups separated by _
//        dd($openapi->components->schemas['Product.jsonld-product.read_product.details']);


//        dd($openapi);

        $locale = 'en'; // @todo
        $index = $this->meiliService->getIndexEndpoint($indexName);
        $settings = $index->getSettings();
        $sorting[] = ['value' => $indexName, 'label' => 'relevancy'];
        foreach ($settings['sortableAttributes'] as $sortableAttribute) {
            foreach (['asc','desc'] as $direction) {
                $sorting[] = [
                    'label' => sprintf("%s %s", $sortableAttribute, $direction),
                    'value' => sprintf("%s:%s:%s", $indexName, $sortableAttribute, $direction)
                ];
            }
        }


        $facets = $settings['filterableAttributes'];

        // this is specific to our way of handling related, translated messages
        $related = $this->meiliService->getRelated($facets, $indexName, $locale);
        // use proxy for translations or hidden
        $params = [
            'server' =>
                $useProxy
                    ? $this->router->generate('meili_proxy', [],
                    UrlGeneratorInterface::ABSOLUTE_URL)
                    : $this->meiliServer,

            'apiKey' => $this->apiKey,
            'indexName' => $indexName,
            'facets' => $facets,
            'sorting' => $sorting,
            'settings' => $settings,
            'endpoint' => $endpoint,
            'embedder' => $embedder,
            'related' => $related, // the facet lookups
        ];
        return $params;
    }





}
