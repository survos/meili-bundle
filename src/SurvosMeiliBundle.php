<?php

namespace Survos\MeiliBundle;

use Psr\Http\Client\ClientInterface;
use Survos\CoreBundle\HasAssetMapperInterface;
use Survos\CoreBundle\Traits\HasAssetMapperTrait;
use Survos\InspectionBundle\Services\InspectionService;
use Survos\MeiliBundle\Components\InstantSearchComponent;
use Survos\MeiliBundle\Command\CreateCommand;
use Survos\MeiliBundle\Command\IndexCommand;
use Survos\MeiliBundle\Command\ListCommand;
use Survos\MeiliBundle\Command\SettingsCommand;
use Survos\MeiliBundle\Controller\MeiliAdminController;
use Survos\MeiliBundle\Controller\MeiliController;
use Survos\MeiliBundle\Controller\SearchController;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SurvosMeiliBundle extends AbstractBundle implements HasAssetMapperInterface
{
    use HasAssetMapperTrait;

    protected string $extensionAlias = 'survos_meili';

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        $id = 'meili_service';
        $builder->autowire(SettingsService::class)
            ->setPublic(true);
        $builder->autowire(MeiliService::class)
//            ->setArgument('$entityManager', new Reference('doctrine.orm.entity_manager'))
            ->setArgument('$config', $config)
            ->setArgument('$meiliHost', $config['host'])
            ->setArgument('$adminKey', $config['apiKey'])
            ->setArgument('$searchKey', $config['searchKey'])
            ->setArgument('$httpClient', new Reference('httplug.http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$bag', new Reference('parameter_bag'))
            ->setAutowired(true)
            ->setPublic(true)
            ->setAutoconfigured(true);
//        dd($config);
        $container->services()->alias($id, MeiliService::class);

        // we don't need both controllers!  But we do anyway, a mess
//        $builder->autowire(MeiliAdminController::class)
//            ->addTag('container.service_subscriber')
//            ->addTag('controller.service_arguments')
//            ->setArgument('$coreName', $config['core_name'])
//            ->setArgument('$meiliService', new Reference($id)) // @todo: move from api to meiliadmin
//            ->setArgument('$chartBuilder', new Reference('chartjs.builder', ContainerInterface::NULL_ON_INVALID_REFERENCE))
//            ->setAutoconfigured(true)
//            ->setPublic(true);
//
        foreach ([IndexCommand::class, SettingsCommand::class, ListCommand::class, CreateCommand::class] as $class) {
            $builder->autowire($class)
                ->setPublic(true)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }

//        $builder->register($id = 'api_meili_service', MeiliService::class)
//            ->setArgument('$config', $config)
//            // @todo: array of hosts?
//            ->setArgument('$meiliHost', $config['host'])
//            ->setArgument('$adminKey', $config['apiKey'])
//            ->setArgument('$searchKey', $config['searchKey'])
//            ->setArgument('$httpClient', new Reference('httplug.http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE))
//            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
//            ->setArgument('$bag', new Reference('parameter_bag'))
//            ->setAutoconfigured(true)
//            ->setAutowired(true)
//            ->setPublic(true);


        $builder->autowire(MeiliController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
//            ->setArgument('$meiliService', new Reference($id))
            ->setArgument('$chartBuilder', new Reference('chartjs.builder', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setAutoconfigured(true)
            ->setPublic(true);

        $builder->autowire(SearchController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setArgument('$meiliService', new Reference($id))
            ->setAutoconfigured(true)
            ->setPublic(true);

        $builder->register(InstantSearchComponent::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$twig', new Reference('twig'))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
//            ->setArgument('$datatableService', new Reference(DatatableService::class))
            ->setArgument('$inspectionService', new Reference(InspectionService::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$meiliService', new Reference($id))
            //            ->setArgument('$stimulusController', $config['stimulus_controller']);
        ;


//// 1) register the adapter
//        $builder->register('survos_meili.psr18_client', Psr18Client::class)
//            ->addArgument(new Reference(HttpClientInterface::class))
//            ->setPublic(false)
//            ->setAutowired(true)
//        ;
//
//// 2) alias the PSR-18 interface to your adapter
//        $builder->setAlias(ClientInterface::class, 'survos_meili.psr18_client')
//            ->setPublic(false)
//        ;

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('core_name')->defaultValue('core')
            ->info("a key when diverse types share an index, e.g. table or core")
            ->end()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->scalarNode('host')->defaultValue('%env(default::MEILI_SERVER)%')->end()
            ->scalarNode('apiKey')->defaultValue('%env(default::MEILI_ADMIN_KEY)%')->end()
            ->scalarNode('searchKey')->defaultValue('%env(default::MEILI_SEARCH_KEY)%')->end()
            ->scalarNode('meiliPrefix')->defaultValue('%env(default::MEILI_PREFIX)%')->end()
            ->booleanNode('passLocale')->defaultValue(false)->end()
            ->integerNode('maxValuesPerFacet')
            ->info('https://www.meilisearch.com/docs/reference/api/settings#faceting-object')
            ->defaultValue(1000)
            ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isAssetMapperAvailable($builder)) {
            return;
        }

        $dir = realpath(__DIR__ . '/../assets/');
        assert(file_exists($dir), $dir);

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $dir => '@survos/meili',
                ],
            ],
        ]);
    }

    public function getPaths(): array
    {
        $dir = realpath(__DIR__ . '/../assets/');
        assert(file_exists($dir), $dir);
        return [$dir => '@survos/meili'];

    }


}
