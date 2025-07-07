<?php

namespace Survos\MeiliBundle\Controller;

use Adbar\Dot;
use cebe\openapi\Reader;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class MeiliAdminController extends AbstractController
{
    public function __construct(
        private MeiliService  $meili,
        private ?ChartBuilderInterface $chartBuilder = null,
        private string $coreName='core'
    )
    {
//        $this->helper = $helper;
    }

    //
    #[Route(path: '/docs', name: 'meili_admin_docs', methods: ['GET'])]
    #[Template('@SurvosMeiliAdmin/docs.html.twig')]
    public function docs(): Response|array
    {
        $url = 'https://raw.githubusercontent.com/meilisearch/open-api/refs/heads/main/open-api.json';
        $data = json_decode(file_get_contents($url), true);
        $dot = new Dot($data);


// realpath is needed for resolving references with relative Paths or URLs
//        $openapi = Reader::readFromJsonFile($url);
        return [
            'json' => $dot,
        ];
    }


    #[Route(path: '/facet/{indexName}/{fieldName}/{max}', name: 'survos_facet_show', methods: ['GET'])]
    public function facet(string $indexName, string $fieldName,
                          #[MapQueryParameter] ?string $tableName=null,
                          int $max = 25): Response
    {
        $index = $this->meili->getIndex($indexName);

        $params = ['limit' => 0,
            'facets' => [$fieldName]];
        if ($tableName) {
            $params['filter'] = $this->coreName . "=" . $tableName;
        }
        $data = $index->rawSearch("", $params);
//        dd($data, indexName: $indexName, tableName: $tableName, fieldName: $fieldName, params: $params, dist: $data['facetDistribution'][$fieldName]);

        $facetDistributionCounts = $data['facetDistribution'][$fieldName]??[];
//        $translations = $projectService->getNonObjectTranslations($project->getCode(), $field->getCoreCode(), $locale); // , '=');
        $counts = [];
        foreach ($facetDistributionCounts as $label => $count) {
            $counts[] = [
                'label' => $label,
                'count' => $count
            ];
        }
        $chartData = [];
        foreach (array_slice($counts, 0, $max) as $count) {
            $chartData[$count['label'] ?? $count['code']] = $count['count'];
        }
        $chart = null;
        if ($this->chartBuilder) {
            $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
            $chart->setData([
                'labels' => array_keys($chartData),
                'datasets' => [
                    [
                        'label' => 'Data Distribution',
                        'backgroundColor' => array_map(fn ($x) => sprintf('rgb(%d, %d, %d', random_int(0, 255), random_int(0, 255), random_int(0, 255)), array_values($chartData)),
                        'borderColor' => 'rgb(255, 99, 132)',
                        'data' => array_values($chartData),
                    ],
                ],
            ]);

            $chart->setOptions([
                'maintainAspectRatio' => false,
            ]);

        }

        return $this->render('@SurvosApiGrid/facet.html.twig', get_defined_vars() + [
                'tableData' => $counts,
                'chartData' => $chartData,
                'chart' => $chart,
                'currentField' => $fieldName,
                'indexName' => $indexName,
                'max' => $max,
                // we need the facets to get a menu. Alas, this needs to run without the bootstrap bundle
                'facetFields' =>  $index->getFilterableAttributes(),
            ]);
    }

    //defaults={"anything" = null}, requirements={"anything"=".+"}
    #[Route('/meili/admin{anything}', name: 'survos_meili_admin', defaults: ['anything' => null], requirements: ['anything' => '.+'])]
    public function dashboard(UrlGeneratorInterface $urlGenerator): Response
    {
        $config = json_decode(<<<END
{
  "modulePrefix": "meiliadmin",
  "environment": "production",
  "rootURL": "/",
  "locationType": "history",
  "EmberENV": {
    "FEATURES": {},
    " APPLICATION_TEMPLATE_WRAPPER": false,
    " DEFAULT_ASYNC_OBSERVERS": true,
    " JQUERY_INTEGRATION": false,
    " TEMPLATE_ONLY_GLIMMER_COMPONENTS": true
  },
  "APP": { 
    "meilisearch": { "url": "http://localhost:7700", "key": "MASTER_KEY" },
    "name": "meiliadmin", 
    "version": "0.0.0+bd7f85d7" 
  }
}
END
);

        // this seems problematic
        $config->rootURL = $urlGenerator->generate('survos_meili_admin');
//        dd($config, json_encode($config, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES));
//        return $this->render('@SurvosBootstrap/base.html.twig', [
        return $this->render('@SurvosMeiliAdmin/dashboard.html.twig', [
            'config' => $config,
            'encodedConfig' => json_encode($config),
            'controller_name' => 'MeiliAdminController',
        ]);
    }

    //defaults={"anything" = null}, requirements={"anything"=".+"}
    #[Route('/riccox/{anything}', name: 'riccox_meili_admin', defaults: ['anything' => null], requirements: ['anything' => '.+'])]
    public function riccox(Request $request, UrlGeneratorInterface $urlGenerator): Response
    {
        return $this->render('@SurvosMeiliAdmin/riccox.html.twig', [
        ]);

    }


    #[Route(path: '/stats/{indexName}.{_format}', name: 'survos_index_stats', methods: ['GET'])]
    public function stats(
        string  $indexName,
        Request $request,
        string $_format='html'
    ): Response
    {
        $index = $this->meili->getIndex($indexName);
        $stats = $index->stats();
        // idea: meiliStats as a component?
        $data =  [
            'indexName' => $indexName,
            'settings' => $index->getSettings(),
            'stats' => $stats
        ];
        return $_format == 'json'
            ? $this->json($data)
            : $this->render('@SurvosApiGrid/stats.html.twig', $data);

        // Get the base URL
//        $url = "/api/projects";//.$indexName;
        $url = "/api/" . $indexName;
        $queryParams = ['limit' => 0, 'offset' => 0, '_index' => false];
        $queryParams['_locale'] = $translator->getLocale();
        $settings = $index->getSettings();
        foreach ($settings['filterableAttributes'] as $filterableAttribute) {
            $queryParams['facets'][$filterableAttribute] = 1;
        }
        $queryParams = http_build_query($queryParams);

        $data = $client->request('GET', $finalUrl = $baseUrl . $url . "?" . $queryParams, [
            'headers' => [
                'Content-Type' => 'application/ld+json;charset=utf-8',
            ]
        ]);

        dd($finalUrl, $data->getStatusCode());
        assert($index);
        return $this->render('meili/stats.html.twig', [
            'stats' => $index->stats(),
            'settings' => $index->getSettings()
        ]);


    }

}
