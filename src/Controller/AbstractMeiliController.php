<?php

namespace Survos\MeiliBundle\Controller;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Meilisearch\Exceptions\ApiException;
use Survos\MeiliBundle\Bridge\EasyAdmin\MeiliEasyAdminDashboardHelper;
use Survos\MeiliBundle\Bridge\EasyAdmin\MeiliEasyAdminMenuFactory;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use Symfony\Contracts\Service\Attribute\Required;
use function Symfony\Component\Translation\t;

//#[Route('/meili')]
//#[AdminDashboard('/ez', '/default-meili')]
abstract class AbstractMeiliController extends AbstractDashboardController
{

    const MEILI_ROUTE = 'meili_admin';
    protected $helper;

//    public function __construct(
//        private MeiliService           $meiliService,
//        private ?ChartBuilderInterface $chartBuilder = null,
//    )
//    {
////        $this->helper = $helper;
//    }


    #[Required]
    public MeiliService $meiliService;
    protected ?ChartBuilderInterface $chartBuilder = null;
    #[Required]
    public UrlGeneratorInterface $urlGenerator;

    #[Required]
    public MeiliEasyAdminMenuFactory $menuFactory;

    #[Required]
    public MeiliEasyAdminDashboardHelper $dashboardHelper;

    #[Required]
    public KernelInterface $kernel;


    #[Required]
    public function setChartBuilder(?ChartBuilderInterface $chartBuilder = null): void
    {
        $this->chartBuilder = $chartBuilder;
    }

    public function getMeiliRoute(): string
    {
        return self::MEILI_ROUTE;
    }

    public function index(): Response
    {
        return $this->render(
            $this->dashboardHelper->getDashboardTemplate(),
            $this->dashboardHelper->getDashboardParameters($this->getMeiliRoute())
        );
    }

    public function configureDashboard(): Dashboard
    {
        return $this->dashboardHelper
            ->configureDashboard(Dashboard::new())
            ;
    }


    /**
     * @return iterable<MenuItem>
     */
    public function configureMenuItems(): iterable
    {
        $translationDomain = 'meili'; /// change this for application-specific translations

        // Main navigation
        yield MenuItem::linkToDashboard(
            t('page_title.dashboard', [], 'EasyAdminBundle'),
            $this->dashboardHelper->getIcon('home')
        );

//        yield MenuItem::section('content_management', 'fas fa-folder-open');
        yield from $this->menuFactory->createIndexMenus(self::MEILI_ROUTE);

//        yield MenuItem::section('tools', 'fas fa-wrench');
        yield from $this->menuFactory->createToolsMenuItems();

//        yield MenuItem::linkToUrl('search_analytics', 'fas fa-chart-line', '#')
////            ->setPermission('ROLE_ADMIN')
//        ;
    }


    public function configureAssets(): Assets
    {
        return Assets::new()
            ->useCustomIconSet() // use ux_icons
            ->addAssetMapperEntry($this->getAssetEntityName())  // Your main .js entry, must be configured in importap. Use admin to avoid tabler/bootstrap conflicts
            ;
    }


    public function getAssetEntityName(): string {
        $importMapPath = $this->kernel->getProjectDir() . '/importmap.php';
        if (file_exists($importMapPath)) {
            $entries = include $importMapPath;
            if (isset($entries['admin'])) {
                return 'admin';
            }
        }
        return 'app';
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
