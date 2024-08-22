<?php

namespace Survos\MeiliAdminBundle;

use Survos\MeiliAdminBundle\Controller\MeiliAdminController;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosMeiliAdminBundle extends AbstractBundle
{
    protected string $extensionAlias = 'survos_meili_admin';

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        $builder->autowire(MeiliAdminController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setArgument('$coreName', $config['core_name'])
            ->setArgument('$meili', new Reference('api_meili_service')) // @todo: move from api to meiliadmin
            ->setArgument('$chartBuilder', new Reference('chartjs.builder', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setAutoconfigured(true)
            ->setPublic(true)
        ;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('core_name')->defaultValue('core')
                ->info("a key when diverse types share an index, e.g. table or core")
            ->end()

            ->booleanNode('enabled')->defaultTrue()->end()
            ->end();
    }

}
