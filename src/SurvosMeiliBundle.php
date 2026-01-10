<?php

namespace Survos\MeiliBundle;

use Survos\BabelBundle\Service\LocaleContext;
use Survos\CoreBundle\HasAssetMapperInterface;
use Survos\CoreBundle\Traits\HasAssetMapperTrait;
use Survos\MeiliBundle\Bridge\EasyAdmin\MeiliEasyAdminDashboardHelper;
use Survos\MeiliBundle\Bridge\EasyAdmin\MeiliEasyAdminMenuFactory;
use Survos\MeiliBundle\Command\ExportCommand;
use Survos\MeiliBundle\Command\IterateIndexesCommand;
use Survos\MeiliBundle\Command\MeilEstimatorCommand;
use Survos\MeiliBundle\Command\MeiliFlushFileCommand;
use Survos\MeiliBundle\Command\MeiliRegistryReportCommand;
use Survos\MeiliBundle\Command\MeiliSchemaCreateCommand;
use Survos\MeiliBundle\Command\MeiliSchemaUpdateCommand;
use Survos\MeiliBundle\Command\MeiliSchemaValidateCommand;
use Survos\MeiliBundle\Command\MeiliSuggestSettingsCommand;
use Survos\MeiliBundle\Command\PopulateCommand;
use Survos\MeiliBundle\Command\SyncIndexesCommand;
use Survos\MeiliBundle\Compiler\MeiliIndexPass;
use Survos\MeiliBundle\Components\InstantSearchComponent;
use Survos\MeiliBundle\Controller\MeiliAdminController;
use Survos\MeiliBundle\Controller\MeiliProxyController;
use Survos\MeiliBundle\Controller\SearchController;
use Survos\MeiliBundle\Controller\TemplateController;
use Survos\MeiliBundle\EventListener\DoctrineEventListener;
use Survos\MeiliBundle\MessageHandler\BatchIndexEntitiesMessageHandler;
use Survos\MeiliBundle\Registry\MeiliRegistry;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Survos\MeiliBundle\Service\IndexFastSyncService;
use Survos\MeiliBundle\Service\IndexNameResolver;
use Survos\MeiliBundle\Service\IndexProducer;
use Survos\MeiliBundle\Service\IndexSyncService;
use Survos\MeiliBundle\Service\MeiliFieldHeuristic;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\MeiliServiceConfig;
use Survos\MeiliBundle\Service\SettingsService;
use Survos\MeiliBundle\Service\TargetPlanner;
use Survos\MeiliBundle\Util\ResolvedEmbeddersProvider;
use Survos\MeiliBundle\Util\TextFieldResolver;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Twig\Environment;

class SurvosMeiliBundle extends AbstractBundle implements HasAssetMapperInterface
{
    use HasAssetMapperTrait;

    protected string $extensionAlias = 'survos_meili';

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services
            ->set(DoctrineEventListener::class)
            ->autowire()
            ->autoconfigure()
            ->tag('doctrine.event_listener', ['event' => 'postFlush'])
            ->tag('doctrine.event_listener', ['event' => 'postUpdate'])
            ->tag('doctrine.event_listener', ['event' => 'preRemove'])
            ->tag('doctrine.event_listener', ['event' => 'postPersist']);

        if (class_exists(\EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem::class)) {
            $builder->autowire(MeiliEasyAdminMenuFactory::class)
                ->setPublic(true)
                ->setAutoconfigured(true);
        }

        foreach ([SettingsService::class, MeiliPayloadBuilder::class] as $class) {
            $builder->autowire($class)->setPublic(true);
        }

        $builder->autowire(ResolvedEmbeddersProvider::class)
            ->setArgument(0, $config['embedders'] ?? [])
            ->setPublic(true);

        // Parameters
        $builder->setParameter('survos_meili.entity_dirs', $config['entity_dirs']);
        $builder->setParameter('survos_meili.prefix', $config['meiliPrefix']);
        $builder->setParameter('survos_meili.pricing', $config['pricing'] ?? []);
        $builder->setParameter('survos_meili.meili_settings', $config['meili_settings'] ?? []);

        // IMPORTANT: define MeiliServiceConfig via factory (no object literals in container dump)
        $builder->register(MeiliServiceConfig::class)
            ->setFactory([MeiliServiceConfig::class, 'fromArray'])
            ->setArguments([$config])
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);

        // Index name resolver (uses MeiliRegistry + ParameterBag + MeiliServiceConfig)
        $builder->autowire(IndexNameResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true);

        // Meili service
        $builder->autowire(MeiliService::class)
            ->setArgument('$config', $config)
            ->setArgument('$meiliHost', $config['host'])
            ->setArgument('$adminKey', $config['apiKey'])
            ->setArgument('$searchKey', $config['searchKey'])
            ->setArgument('$httpClient', new Reference('httplug.http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$bag', new Reference('parameter_bag'))
            ->setArgument('$indexedEntities', []) // placeholder; replaced by MeiliIndexPass merge
            ->setAutowired(true)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $container->services()->alias('meili_service', MeiliService::class);

        // Registry
        $builder->autowire(MeiliRegistry::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setArgument('$indexEntities', '%meili.index_entities%')
            ->setArgument('$indexSettings', '%meili.index_settings%')
            ->setArgument('$prefix', '%survos_meili.prefix%');

        $builder->autowire(PopulateCommand::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->addTag('console.command')
            ->setProperty('localeContext', new Reference(
                LocaleContext::class,
                ContainerInterface::IGNORE_ON_INVALID_REFERENCE
            ));

        // Commands
        foreach ([
            MeiliRegistryReportCommand::class,
            ExportCommand::class,
            IterateIndexesCommand::class,
            MeiliSchemaCreateCommand::class,
            MeiliSchemaUpdateCommand::class,
            MeilEstimatorCommand::class,
            MeiliSchemaValidateCommand::class,
            MeiliFlushFileCommand::class,
            MeiliSuggestSettingsCommand::class,
        ] as $class) {
            $builder->autowire($class)
                ->setPublic(true)
                ->setAutoconfigured(true)
                ->addTag('console.command')
                ->setProperty('localeContext', new Reference(
                    LocaleContext::class,
                    ContainerInterface::IGNORE_ON_INVALID_REFERENCE
                ));
            ;
        }

        // Other services (NOTE: MeiliServiceConfig removed from this list)
        foreach ([
            IndexSyncService::class,
            TargetPlanner::class,
            IndexProducer::class,
            MeiliEasyAdminDashboardHelper::class,
            MeiliNdjsonUploader::class,
            MeiliFieldHeuristic::class,
            SyncIndexesCommand::class,
            IndexFastSyncService::class,
            TextFieldResolver::class,
            BatchIndexEntitiesMessageHandler::class,
        ] as $class) {
            $builder->autowire($class)
                ->setPublic(true)
                ->setAutoconfigured(true);
        }

        foreach ([IndexInfoRepository::class] as $class) {
            $builder->autowire($class)
                ->setPublic(true)
                ->addTag('doctrine.repository_service')
                ->setAutoconfigured(true);
        }

        foreach ([MeiliAdminController::class] as $class) {
            $builder->autowire($class)
                ->addTag('container.service_subscriber')
                ->addTag('controller.service_arguments')
                ->addTag('ea.admin_route_controller')
                ->setAutoconfigured(false)
                ->setPublic(true);
        }

        foreach ([MeiliProxyController::class] as $class) {
            $builder->autowire($class)
                ->addTag('container.service_subscriber')
                ->addTag('controller.service_arguments')
                ->setAutoconfigured(true)
                ->setPublic(true);
        }


        $builder->autowire(SearchController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setArgument('$meiliService', new Reference('meili_service'))
            ->setAutoconfigured(true)
            ->setPublic(true);

        $builder->autowire(TemplateController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setArgument('$meiliService', new Reference('meili_service'))
            ->setAutoconfigured(true)
            ->setPublic(true);

        $builder->register(InstantSearchComponent::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$twig', new Reference('twig'))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$meiliService', new Reference('meili_service'));
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('core_name')->defaultValue('core')->end()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->scalarNode('host')->defaultValue('%env(default::MEILI_SERVER)%')->end()
            ->scalarNode('apiKey')->defaultValue('%env(default::MEILI_ADMIN_KEY)%')->end()
            ->scalarNode('transport')->defaultValue('%env(default::MEILI_TRANSPORT)%')->end()
            ->scalarNode('searchKey')->defaultValue('%env(default::MEILI_SEARCH_KEY)%')->end()
            ->scalarNode('meiliPrefix')->defaultValue('%env(default::MEILI_PREFIX)%')->end()
            ->scalarNode('translationStyle')->defaultValue('simple')->end()
            ->booleanNode('passLocale')->defaultFalse()->end()
            ->booleanNode('multiLingual')->info('turn on multi-lingual indexing')->defaultFalse()->end()
            ->integerNode('maxValuesPerFacet')->defaultValue(1000)->end()
            ->arrayNode('tools')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('url')->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('embedders')
                ->useAttributeAsKey('name')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('source')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('model')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('apiKey')->defaultNull()->end()
                        ->scalarNode('for')->defaultNull()->end()
                        ->scalarNode('template')->defaultNull()->end()
                        ->integerNode('documentTemplateMaxBytes')->defaultValue(4096)->end()
                        ->integerNode('maxTokensPerDoc')->defaultNull()->end()
                        ->arrayNode('examples')->scalarPrototype()->end()->defaultValue([])->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('pricing')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('embedders')
                        ->useAttributeAsKey('model')
                        ->scalarPrototype()->end()
                        ->defaultValue([
                            'text-embedding-3-small' => 0.02,
                            'text-embedding-3-large' => 0.13,
                            'text-embedding-ada-002' => 0.10,
                        ])
                    ->end()
                ->end()
            ->end()
            ->arrayNode('meili_settings')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('typoTolerance')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->integerNode('oneTypo')->defaultValue(5)->end()
                            ->integerNode('twoTypos')->defaultValue(9)->end()
                            ->arrayNode('disableOnWords')->scalarPrototype()->end()->defaultValue([])->end()
                            ->arrayNode('disableOnAttributes')->scalarPrototype()->end()->defaultValue([])->end()
                            ->booleanNode('disableOnNumbers')->defaultFalse()->end()
                        ->end()
                    ->end()
                    ->arrayNode('faceting')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->integerNode('maxValuesPerFacet')->defaultValue(1000)->end()
                            ->arrayNode('sortFacetValuesBy')
                                ->useAttributeAsKey('attribute')
                                ->scalarPrototype()->end()
                                ->defaultValue(['*' => 'count'])
                            ->end()
                        ->end()
                    ->end()
                    ->arrayNode('pagination')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->integerNode('maxTotalHits')->defaultValue(1000)->end()
                        ->end()
                    ->end()
                    ->booleanNode('facetSearch')->defaultTrue()->end()
                    ->scalarNode('prefixSearch')->defaultValue('indexingTime')->end()
                ->end()
            ->end()
            ->arrayNode('entity_dirs')
            ->scalarPrototype()->end()
            ->defaultValue([
                '%kernel.project_dir%/src/Entity',
                '%kernel.project_dir%/src/Index',
            ])
            ->end()

            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', [
                'aliases' => [
                    'home' => 'material-symbols:home-outline',
                    'browse' => 'mdi:list-box-outline',
                    'instant_search' => 'mdi:tag-search-outline',
                    'action.detail' => 'mdi:show-outline',
                    'field.text_editor.view_content' => 'mdi:cogs',
                    'semantic-web' => 'mdi:semantic-web',
                    'semantic' => 'simple-icons:semanticscholar',
                    'database' => 'mdi:database',
                    'search' => 'tabler:search',
                    'meili' => 'tabler:search',
                ],
            ]);
        }

        if (class_exists(\EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle::class)) {
            $builder->prependExtensionConfig('framework', [
                'router' => [
                    'resource' => '%kernel.project_dir%/src/Controller/Admin',
                    'type' => 'attribute',
                ],
            ]);
        }

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

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MeiliIndexPass());
    }
}
