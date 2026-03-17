<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Menu;

use Survos\MeiliBundle\Registry\MeiliRegistry;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MeiliMenuSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MeiliRegistry $registry,
        private readonly ?MeiliService $meiliService = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Only subscribe if tabler-bundle's MenuEvent exists
        if (!class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            return [];
        }

        return [
            \Survos\TablerBundle\Event\MenuEvent::NAVBAR_MENU => 'onNavbarMenu',
        ];
    }

    public function onNavbarMenu($event): void
    {
        // Skip if no indexes are registered
        if (empty($this->registry->names())) {
            return;
        }

        // Check if meili is enabled/available
        $isEnabled = $this->meiliService && method_exists($this->meiliService, 'isEnabled')
            ? $this->meiliService->isEnabled()
            : true;

        if (!$isEnabled) {
            return;
        }

        $menu = $event->getMenu();

        // Add Meili submenu
        $submenu = $this->addSubmenu($menu, 'Meili Search');
        $submenu->addChild('registry', [
            'route' => 'meili_registry',
            'label' => 'Registry',
        ]);
    }

    private function addSubmenu($menu, string $label, ?string $icon = null): mixed
    {
        $submenu = $menu->addChild($label);
        if ($icon) {
            $submenu->setAttribute('icon', $icon);
        }
        return $submenu;
    }
}
