<?php

namespace Survos\MeiliBundle\Controller;

use Adbar\Dot;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Meilisearch\Exceptions\ApiException;
use Survos\MeiliBundle\Service\IndexNameResolver;
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
        private readonly MeiliService $meiliService,
        private readonly IndexNameResolver $resolver,
        private readonly ?ChartBuilderInterface $chartBuilder = null,
        private readonly string $coreName = 'core'
    ) {
    }

//    #[AdminRoute('/meili-index', name: 'meili_index')]
    #[Template('@SurvosMeili/ez/dashboard.html.twig')]
    public function index(AdminContext $context): Response|array
    {
        return [
            'adminContext'  => $context,
            'indexSettings' => $this->meiliService->indexedByClass(),
        ];
    }

//    #[AdminRoute(path: '/index/overview/{indexName}', name: 'meili_index_dashboard')]
    public function indexDashboard(
        AdminContext $context,
        string $indexName, // IMPORTANT: this is a base key, not a UID
    ): Response {
        $baseIndexName = $indexName;
        $requestLocale = strtolower((string) $context->getRequest()->getLocale());

        // Resolve locale policy for this base key.
        // In monolingual mode, we must still apply the prefix via uidFor(), but we should not suffix locale.
        $isMlFor = $this->resolver->isMultiLingualFor($baseIndexName, $requestLocale);
        $uidLocale = $isMlFor ? $requestLocale : null;

        $meiliIndexUid = $this->resolver->uidFor($baseIndexName, $uidLocale, $isMlFor);

        // Configured settings are keyed by base name.
        // Prefer the public accessor to avoid reaching into $meiliService->settings directly.
        $settings = $this->meiliService->getIndexSetting($baseIndexName) ?? [];

        $indexApi = $this->meiliService->getIndexEndpoint($meiliIndexUid);

        // If the index doesn't exist yet, do NOT create it in the controller.
        // This keeps creation in the pipeline/commands and avoids “accidental empty indexes” in prod.
        try {
            $liveSettings = $indexApi->getSettings();

            // NOTE: facets will only return for filterable attributes; requesting '*' can fail
            // depending on Meilisearch version/settings. We keep this call for overview,
            // but each facet below requests a single field.
            $results = $indexApi->search(null, [
                'limit'  => 0,
                'facets' => ['*'],
            ]);

            $rawInfo = $indexApi->fetchRawInfo();
        } catch (ApiException $e) {
            if ((int) $e->getCode() === 404) {
                return $this->render('@SurvosMeili/index/show.html.twig', [
                    'indexName'     => $baseIndexName,
                    'resolvedUid'   => $meiliIndexUid,
                    'rawInfo'       => null,
                    'stats'         => null,
                    'settings'      => $settings,
                    'facetCounts'   => [],
                    'facetCharts'   => [],
                    'adminContext'  => $context,
                    'error'         => sprintf('Index "%s" not found on server (resolved uid: "%s"). Create/populate it first.', $baseIndexName, $meiliIndexUid),
                ]);
            }

            throw $e;
        }

        $stats = $indexApi->stats();

        $facetCounts = [];
        $facetCharts = [];

        // IMPORTANT: facet distribution requires the field to be filterable.
        // If a field is not in filterableAttributes, Meilisearch will throw invalid_search_facets.
        foreach (($settings['facets'] ?? []) as $fieldName => $details) {
            try {
                $params = [
                    'limit'  => 0,
                    'facets' => [$fieldName],
                ];

                $data = $indexApi->rawSearch('', $params);
            } catch (ApiException $e) {
                // Keep the dashboard resilient: record the failure per field rather than breaking the page.
                $facetCounts[$fieldName] = [[
                    'label' => sprintf('Facet error: %s', $e->getMessage()),
                    'count' => 0,
                ]];
                continue;
            }

            $facetDistributionCounts = $data['facetDistribution'][$fieldName] ?? [];
            $counts = [];

            foreach ($facetDistributionCounts as $label => $count) {
                $counts[] = [
                    'label' => $label,
                    'count' => $count,
                ];
            }

            $facetCounts[$fieldName] = $counts;

            if ($this->chartBuilder && \count($counts) > 0) {
                $facetCharts[$fieldName] = $this->buildFacetChart($fieldName, $counts);
            }
        }

        return $this->render('@SurvosMeili/index/show.html.twig', [
            'indexName'    => $baseIndexName,
            'resolvedUid'  => $meiliIndexUid,
            'facetCounts'  => $facetCounts,
            'facetCharts'  => $facetCharts,
            'rawInfo'      => $rawInfo,
            'stats'        => $stats,
            'settings'     => $settings,
            'adminContext' => $context,
        ]);
    }

    private function buildFacetChart(string $fieldName, array $counts, int $maxItems = 10): Chart
    {
        $chartData = array_slice($counts, 0, $maxItems);
        $labels = array_map(fn($c) => mb_substr($c['label'] ?: '(empty)', 0, 25), $chartData);
        $values = array_map(fn($c) => $c['count'], $chartData);

        $chartType = \count($chartData) <= 6 ? Chart::TYPE_PIE : Chart::TYPE_BAR;

        $chart = $this->chartBuilder->createChart($chartType);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'label' => $fieldName,
                'backgroundColor' => array_slice(
                    array_merge(self::CHART_COLORS, self::CHART_COLORS, self::CHART_COLORS),
                    0,
                    \count($chartData)
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
        string $indexName, // base key, not uid
        string $fieldName,
        #[MapQueryParameter] ?string $tableName = null,
        int $max = 25
    ): Response {
        $requestLocale = strtolower((string) $this->getRequestLocaleSafe());
        $isMlFor = $this->resolver->isMultiLingualFor($indexName, $requestLocale);
        $uidLocale = $isMlFor ? $requestLocale : null;

        $uid = $this->resolver->uidFor($indexName, $uidLocale, $isMlFor);

        $index = $this->meiliService->getIndexEndpoint($uid);

        $params = [
            'limit'  => 0,
            'facets' => [$fieldName],
        ];

        if ($tableName) {
            // Quote string values to be safe (Meilisearch filter syntax).
            $params['filter'] = sprintf('%s = "%s"', $this->coreName, addcslashes($tableName, "\"\\"));
        }

        $data = $index->rawSearch('', $params);

        $facetDistributionCounts = $data['facetDistribution'][$fieldName] ?? [];
        $counts = [];

        foreach ($facetDistributionCounts as $label => $count) {
            $counts[] = [
                'label' => $label,
                'count' => $count,
            ];
        }

        $chartData = [];
        foreach (array_slice($counts, 0, $max) as $count) {
            $chartData[$count['label'] ?: '(empty)'] = $count['count'];
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
                        \count($chartData)
                    ),
                    'data' => array_values($chartData),
                ]],
            ]);

            $chart->setOptions([
                'maintainAspectRatio' => false,
            ]);
        }

        return $this->render('@SurvosApiGrid/facet.html.twig', get_defined_vars() + [
            'tableData'    => $counts,
            'chartData'    => $chartData,
            'chart'        => $chart,
            'currentField' => $fieldName,
            'indexName'    => $indexName,
            'resolvedUid'  => $uid,
            'max'          => $max,
            'facetFields'  => $index->getFilterableAttributes(),
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
            'config'        => $config,
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
        string $indexName, // base key, not uid
        Request $request,
        string $_format = 'html'
    ): Response {
        $requestLocale = strtolower((string) $request->getLocale());
        $isMlFor = $this->resolver->isMultiLingualFor($indexName, $requestLocale);
        $uidLocale = $isMlFor ? $requestLocale : null;

        $uid = $this->resolver->uidFor($indexName, $uidLocale, $isMlFor);

        $index = $this->meiliService->getIndexEndpoint($uid);
        $stats = $index->stats();

        $data = [
            'indexName' => $indexName,
            'resolvedUid' => $uid,
            'settings' => $index->getSettings(),
            'stats' => $stats,
        ];

        return $_format === 'json'
            ? $this->json($data)
            : $this->render('@SurvosMeili/stats.html.twig', $data);
    }

    private function getRequestLocaleSafe(): string
    {
        // For controller methods that do not receive Request explicitly.
        $req = $this->container->has('request_stack') ? $this->container->get('request_stack')->getCurrentRequest() : null;
        return $req?->getLocale() ?? 'en';
    }
}
