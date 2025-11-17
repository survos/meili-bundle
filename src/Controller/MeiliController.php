<?php

namespace Survos\MeiliBundle\Controller;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Meilisearch\Exceptions\ApiException;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

#[Route('/meili')]
class MeiliController extends AbstractController
{
    protected $helper;

    public function __construct(
        private MeiliService           $meiliService,
        private ?ChartBuilderInterface $chartBuilder = null,
    )
    {
//        $this->helper = $helper;
    }

    #[AdminRoute(path: '/show/{indexName}', name: 'meili_show_index')]
    public function showIndex(
        AdminContext $context,
        string $indexName,
    ): Response {

        // configured
        $settings = $this->meiliService->settings[$indexName];
        // live
        $index = $this->meiliService->getIndex($indexName, autoCreate: false);


        $indexApi  = $this->meiliService->getIndexEndpoint($indexName);
        $results = $indexApi->search(null, [
            'limit' => 0,
            'facets' => ['*']
        ]);

// Access facet distribution
        $facetDistribution = $results->getFacetDistribution();
        try {
            $rawInfo = $indexApi->fetchRawInfo(); // NOT a task
        } catch (ApiException $e) {
            if ($e->getCode() == 404) {
                $index = $this->meiliService->getOrCreateIndex($indexName, $settings['primaryKey']);

//                $task = $indexApi->create($actualIndexName, );
//                dd($indexApi, $task);
                $task = $index->updateSettings($settings['schema']);
                $info = $indexApi->getSettings();
                // this is an object, NOT a json structure.  No way to get it via the client
                foreach ($info as $key => $value) {
                    if (is_object($value)) {
                        unset($info[$key]);
                    }
                }

            }
        }
        $rawInfo = $indexApi->fetchRawInfo(); // NOT a task
        $stats = $indexApi->stats();
        $facetCounts = [];

        foreach ($settings['facets'] as $fieldName => $details) {

            $params = ['limit' => 0,
                'facets' => [$fieldName]];
            $data = $indexApi->rawSearch("", $params);
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
            $facetCounts[$fieldName] = $counts;
        }
//        $info = $this->meiliService->getMeiliClient()->getRawIndex($actualIndexName);

        return $this->render('@SurvosMeili/index/show.html.twig', [
            'indexName' => $indexName,
            'facetCounts' => $facetCounts,
            'rawInfo' => $rawInfo,
            'stats' => $stats,
            'settings' => $settings,
            'adminContext' => $context,
        ]);
    }


    #[Route(path: '/realtime/abc/{indexName}.{_format}', name: 'survos_meili_realtime_stats', methods: ['GET'])]
    #[Template('@SurvosMeili/_realtime.html.twig')]
    public function realtime_stats(
        string  $indexName,
        string $_format='html'
    ): array
    {
        $index = $this->meiliService->getIndex($indexName);
        $stats = $index->stats();
        return [
            'index' => $index,
            'stats' => $stats,
        ];

    }

    // shouldn't this be in MeiliAdminController
    #[Route(path: '/stats/{indexName}.{_format}', name: 'survos_index_stats_something_wrong', methods: ['GET'])]
    public function stats(
        string  $indexName,
        Request $request,
        string $_format='html'
    ): Response
    {
        $index = $this->meiliService->getIndex($indexName);
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


    #[Route('/column/{indexName}/{fieldCode}', name: 'survos_grid_column')]
    public function column(Request $request, string $indexName, string $fieldCode)
    {
        $index = $this->meiliService->getIndex($indexName);
        $settings = $index->getSettings();
        $stats = $index->stats();
        // idea: meiliStats as a component?
        $data =  [
            'indexName' => $indexName,
            'settings' => $index->getSettings(),
            'stats' => $stats
        ];

        dd($indexName, $settings);
        // inspect the entity and colum?
        // this gets the facet data from meili, though it could get it from a dedicated Field entity in this bundle
//        dd($fieldCode, $index);

        return $this->render("@SurvosApiGrid/facet.html.twig", [
            'configs' => $this->helper->getWorkflowConfiguration(),
            'workflowsGroupedByClass' => $workflowsGroupedByClass,
            'workflowsByCode' => $workflowsGroupedByCode,
        ]);
    }


}
