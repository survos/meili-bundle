<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Bridge\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\IndexNameResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MeiliEasyAdminDashboardHelper
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly IndexNameResolver $indexNameResolver,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales = [],
        #[Autowire('%survos_meili.meili_ui_url%')]
        private readonly string $meiliUiUrl = 'http://127.0.0.1:24900/ins/0',
        #[Autowire('%survos_meili.chat%')]
        private readonly array $chatConfig = [],
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
        // Enrich each settings entry with the resolved Meilisearch UID.
        $allSettings = [];
        foreach ($this->meiliService->settings as $baseName => $settings) {
            $settings['uid'] = $this->indexNameResolver->uidFor($baseName, null);
            $allSettings[$baseName] = $settings;
        }

        $workspaces = $this->chatConfig['workspaces'] ?? [];
        $defaultWorkspace = $workspaces !== [] ? array_key_first($workspaces) : null;

        return [
            'prefix'           => $dashboardPrefix,
            'allSettings'      => $allSettings,
            'meiliUiUrl'       => $this->meiliUiUrl,
            'defaultWorkspace' => $defaultWorkspace,
        ];
    }
}
