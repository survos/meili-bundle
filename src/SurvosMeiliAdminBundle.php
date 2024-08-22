<?php

namespace Survos\MeiliAdminBundle;

use Survos\MeiliAdminBundle\Controller\MeiliAdminController;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosMeiliAdminBundle extends AbstractBundle
{
    protected string $extensionAlias = 'survos_meili_admin';

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        $builder->autowire(MeiliAdminController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setAutoconfigured(true)
            ->setPublic(true)
        ;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->end();
    }

}
