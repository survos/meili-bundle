<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Menu;

use Survos\MeiliBundle\Registry\MeiliRegistry;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\TablerBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class MeiliMenuSubscriber
{
    public function __construct(
        private readonly MeiliRegistry $registry,
        private readonly ?MeiliService $meiliService = null,
        private readonly ?string $meiliHost = null,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {}

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        if (empty($this->registry->names())) {
            return;
        }

        if ($this->meiliService && method_exists($this->meiliService, 'isEnabled') && !$this->meiliService->isEnabled()) {
            return;
        }

        $menu = $event->getMenu();
        $submenu = $menu->addChild('Meili Search');

        $submenu->addChild('Registry', [
            'route' => 'meili_registry',
        ]);

        $submenu->addChild('Index of Indexes', [
            'route' => 'meili_insta',
            'routeParameters' => ['indexName' => 'index_info'],
        ]);

        if ($this->meiliHost) {
            $submenu->addChild('Meili Server', [
                'uri' => $this->meiliHost,
                'linkAttributes' => ['target' => '_blank'],
            ]);

            $submenu->addChild('Riccox UI', [
                'uri' => 'https://meilisearch-ui.vercel.app/',
                'linkAttributes' => ['target' => '_blank'],
            ]);
        }
    }
}
