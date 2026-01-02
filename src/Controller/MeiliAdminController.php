<?php

namespace Survos\MeiliBundle\Controller;

use Adbar\Dot;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Meilisearch\Exceptions\ApiException;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class MeiliAdminController extends AbstractController
{
    private const CHART_COLORS = [
        'rgba(54, 162, 235, 0.8)',
        'rgba(255, 99, 132, 0.8)',
        'rgba(255, 206, 86, 0.8)',
        'rgba(75, 192, 192, 0.8)',
        'rgba(153, 102, 255, 0.8)',
        'rgba(255, 159, 64, 0.8)',
        'rgba(199, 199, 199, 0.8)',
        'rgba(83, 102, 255, 0.8)',
        'rgba(255, 99, 255, 0.8)',
        'rgba(99, 255, 132, 0.8)',
    ];

    public function __construct(
        private MeiliService           $meiliService,
        private ?ChartBuilderInterface $chartBuilder = null,
        private string                 $coreName = 'core'
    ) {
    }

    #[AdminRoute('/meili-index', name: 'meili_index')]
    #[Template('@SurvosMeili/ez/dashboard.html.twig')]
    public function index(AdminContext $context): Response|array
    {
        return [
            'adminContext' => $context,
            'indexSettings' => $this->meiliService->indexedByClass()
        ];
    }

    #[AdminRoute(path: '/index/overview/{indexName}', name: 'meili_index_dashboard')]
    public function indexDashboard(
        AdminContext $context,
        string       $indexName,
    ): Response {

        $baseIndexName = $indexName;
        $locale = $context->getRequest()->getLocale();

        // Resolve the actual Meilisearch UID once, based on bundle configuration
        if ($this->meiliService->isMultiLingual) {
            $meiliIndexUid = $this->meiliService->localizedUid($baseIndexName, $locale);
        } else {
            $meiliIndexUid = $baseIndexName;
        }

        // configured
        $settings = $this->meiliService->settings[$indexName];

        $indexApi = $this->meiliService->getIndexEndpoint($meiliIndexUid);
        $liveSettings = $indexApi->getSettings();
        $results = $indexApi->search(null, [
            'limit' => 0,
            'facets' => ['*']
        ]);

        $facetDistribution = $results->getFacetDistribution();
        try {
            $rawInfo = $indexApi->fetchRawInfo();
        } catch (ApiException $e) {
            if ($e->getCode() == 404) {
                $index = $this->meiliService->getOrCreateIndex($indexName, $settings['primaryKey']);
                $task = $index->updateSettings($settings['schema']);
                $info = $indexApi->getSettings();
                foreach ($info as $key => $value) {
                    if (is_object($value)) {
                        unset($info[$key]);
                    }
                }
            }
        }
        $rawInfo = $indexApi->fetchRawInfo();
        $stats = $indexApi->stats();
        $facetCounts = [];
        $facetCharts = [];

        foreach ($settings['facets'] as $fieldName => $details) {
            $params = ['limit' => 0, 'facets' => [$fieldName]];
            $data = $indexApi->rawSearch("", $params);

            $facetDistributionCounts = $data['facetDistribution'][$fieldName] ?? [];
            $counts = [];
            foreach ($facetDistributionCounts as $label => $count) {
                $counts[] = [
                    'label' => $label,
                    'count' => $count
                ];
            }
            $facetCounts[$fieldName] = $counts;

            // Build chart if chartBuilder is available
            if ($this->chartBuilder && count($counts) > 0) {
                $facetCharts[$fieldName] = $this->buildFacetChart($fieldName, $counts);
            }
        }

        return $this->render('@SurvosMeili/index/show.html.twig', [
            'indexName' => $indexName,
            'facetCounts' => $facetCounts,
            'facetCharts' => $facetCharts,
            'rawInfo' => $rawInfo,
            'stats' => $stats,
            'settings' => $settings,
            'adminContext' => $context,
        ]);
    }

    private function buildFacetChart(string $fieldName, array $counts, int $maxItems = 10): Chart
    {
        $chartData = array_slice($counts, 0, $maxItems);
        $labels = array_map(fn($c) => mb_substr($c['label'] ?: '(empty)', 0, 25), $chartData);
        $values = array_map(fn($c) => $c['count'], $chartData);

        // Use pie for small datasets, bar for larger ones
        $chartType = count($chartData) <= 6 ? Chart::TYPE_PIE : Chart::TYPE_BAR;

        $chart = $this->chartBuilder->createChart($chartType);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'label' => $fieldName,
                'backgroundColor' => array_slice(
                    array_merge(self::CHART_COLORS, self::CHART_COLORS, self::CHART_COLORS),
                    0,
                    count($chartData)
                ),
                'data' => $values,
            ]],
        ]);

        $options = [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['display' => $chartType === Chart::TYPE_PIE],
            ],
        ];

        // Add horizontal bar options for better label display
        if ($chartType === Chart::TYPE_BAR) {
            $options['indexAxis'] = 'y';
        }

        $chart->setOptions($options);

        return $chart;
    }

    #[Route(path: '/docs', name: 'meili_admin_docs', methods: ['GET'])]
    #[Template('@SurvosMeiliAdmin/docs.html.twig')]
    public function docs(): Response|array
    {
        $url = 'https://raw.githubusercontent.com/meilisearch/open-api/refs/heads/main/open-api.json';
        $data = json_decode(file_get_contents($url), true);
        $dot = new Dot($data);

        return [
            'json' => $dot,
        ];
    }

    #[Route(path: '/facet/{indexName}/{fieldName}/{max}', name: 'survos_facet_show', methods: ['GET'])]
    public function facet(
        string $indexName,
        string $fieldName,
        #[MapQueryParameter] ?string $tableName = null,
        int    $max = 25
    ): Response {
        $index = $this->meiliService->getIndex($indexName);

        $params = ['limit' => 0, 'facets' => [$fieldName]];
        if ($tableName) {
            $params['filter'] = $this->coreName . "=" . $tableName;
        }
        $data = $index->rawSearch("", $params);

        $facetDistributionCounts = $data['facetDistribution'][$fieldName] ?? [];
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
                'datasets' => [[
                    'label' => 'Data Distribution',
                    'backgroundColor' => array_slice(
                        array_merge(self::CHART_COLORS, self::CHART_COLORS, self::CHART_COLORS),
                        0,
                        count($chartData)
                    ),
                    'data' => array_values($chartData),
                ]],
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
                'facetFields' => $index->getFilterableAttributes(),
            ]);
    }

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

        $config->rootURL = $urlGenerator->generate('survos_meili_admin');

        return $this->render('@SurvosMeiliAdmin/dashboard.html.twig', [
            'config' => $config,
            'encodedConfig' => json_encode($config),
            'controller_name' => 'MeiliAdminController',
        ]);
    }

    #[Route('/riccox/{anything}', name: 'riccox_meili_admin', defaults: ['anything' => null], requirements: ['anything' => '.+'])]
    public function riccox(Request $request, UrlGeneratorInterface $urlGenerator): Response
    {
        return $this->render('@SurvosMeiliAdmin/riccox.html.twig', []);
    }

    #[Route(path: '/stats/{indexName}.{_format}', name: 'survos_index_stats', methods: ['GET'])]
    public function stats(
        string  $indexName,
        Request $request,
        string  $_format = 'html'
    ): Response {
        $index = $this->meiliService->getIndex($indexName);
        $stats = $index->stats();

        $data = [
            'indexName' => $indexName,
            'settings' => $index->getSettings(),
            'stats' => $stats
        ];

        return $_format == 'json'
            ? $this->json($data)
            : $this->render('@SurvosMeili/stats.html.twig', $data);
    }
}
