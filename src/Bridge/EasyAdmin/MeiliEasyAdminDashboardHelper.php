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
    ) {
    }

    public function configureDashboard(Dashboard $dashboard): Dashboard
    {
        return $dashboard
            // translations live in the bundle
            ->setTranslationDomain('meili')
            ->setLocales($this->enabledLocales)
            ->setTitle('Meili Dashboard');
    }

    public function getDashboardTemplate(): string
    {
        // bundle-owned EasyAdmin dashboard template
        return '@SurvosMeili/ez/dashboard.html.twig';
    }

    public function getDashboardParameters(): array
    {
        return [
            'settings' => $this->meiliService->settings,
        ];
    }
}
