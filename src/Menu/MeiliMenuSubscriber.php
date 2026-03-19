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
        private readonly ?string $meiliHost = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        if (!class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            return [];
        }

        return [
            \Survos\TablerBundle\Event\MenuEvent::NAVBAR_MENU => 'onNavbarMenu',
        ];
    }

    public function onNavbarMenu($event): void
    {
        if (empty($this->registry->names())) {
            return;
        }

        $isEnabled = $this->meiliService && method_exists($this->meiliService, 'isEnabled')
            ? $this->meiliService->isEnabled()
            : true;

        if (!$isEnabled) {
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
