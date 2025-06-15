<?php

namespace Survos\MeiliAdminBundle;

use Survos\MeiliAdminBundle\Command\ListCommand;
use Survos\MeiliAdminBundle\Controller\MeiliAdminController;
use Survos\MeiliAdminBundle\Controller\MeiliController;
use Survos\MeiliAdminBundle\Service\MeiliService;
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

        $id = 'meili_service_in_admin';
//        $builder->register($id, MeiliService::class);
        $builder->autowire(MeiliService::class)
//            ->setArgument('$entityManager', new Reference('doctrine.orm.entity_manager'))
            ->setArgument('$config',$config)
            ->setArgument('$meiliHost',$config['meiliHost'])
            ->setArgument('$meiliKey',$config['meiliKey'])
            ->setArgument('$httpClient',new Reference('httplug.http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$bag', new Reference('parameter_bag'))
            ->setAutowired(true)
            ->setPublic(true)
            ->setAutoconfigured(true)
        ;
//        dd($config);
        $container->services()->alias($id, MeiliService::class,);

        // we don't need both controllers!  But we do anyway, a mess
        $builder->autowire(MeiliAdminController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setArgument('$coreName', $config['core_name'])
            ->setArgument('$meili', new Reference($id)) // @todo: move from api to meiliadmin
            ->setArgument('$chartBuilder', new Reference('chartjs.builder', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setAutoconfigured(true)
            ->setPublic(true)
        ;

        foreach ([ListCommand::class] as $class) {
            $builder->autowire($class)
                ->setPublic(true)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }

        $builder->autowire(MeiliController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setArgument('$meili', new Reference($id)) // @todo: move from api to meiliadmin
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
            ->scalarNode('meiliHost')->defaultValue('%env(MEILI_SERVER)%')->end()
            ->scalarNode('meiliKey')->defaultValue('%env(MEILI_API_KEY)%')->end()
            ->scalarNode('meiliPrefix')->defaultValue('%env(MEILI_PREFIX)%')->end()
            ->booleanNode('passLocale')->defaultValue(false)->end()
            ->integerNode('maxValuesPerFacet')
            ->info('https://www.meilisearch.com/docs/reference/api/settings#faceting-object')
            ->defaultValue(1000)
            ->end()
            ->end();
    }

}
