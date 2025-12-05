<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MeiliEasyAdminDashboardHelper
{
    public function __construct(
        private readonly MeiliService $meiliService,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales = [],
        // idea: we could configure the icons in survos_meili to override font awesome
    ) {
    }

    public function getIcon(string $key): string
    {
        return $key;
        // old way.  now put these in the ux_icon config
        $icons = [
            'home' => 'fa fa-home',
            'browse' => 'fas fa-list',
            'instant_search' => 'fa-brands fa-searchengin',
            'action.detail' => 'fa fa-eye',
            'field.text_editor.view_content' => 'fa fa-cogs',
        ];
        return $icons[$key] ?? 'fa fa-bug';

    }

    public function configureDashboard(Dashboard $dashboard): Dashboard
    {
        return $dashboard
            // translations live in the bundle, but are usually overwritten by the app translations (field names, etc.)
//            ->setTranslationDomain('meili')
            ->setLocales($this->enabledLocales)
            ->setTitle('Meili Dashboard');
    }

    public function getDashboardTemplate(): string
    {
        // bundle-owned EasyAdmin dashboard template.  default translation domain is meili
        return '@SurvosMeili/ez/dashboard.html.twig';
    }

    public function getDashboardParameters(string $dashboardPrefix): array
    {
        return [
            'prefix' => $dashboardPrefix,
            'allSettings' => $this->meiliService->settings,
        ];
    }
}
