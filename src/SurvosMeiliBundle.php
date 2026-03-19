<?php

declare(strict_types=1);

namespace Survos\MeiliBundle;

use Survos\BabelBundle\Service\LocaleContext;
use Survos\CoreBundle\HasAssetMapperInterface;
use Survos\CoreBundle\Traits\HasAssetMapperTrait;
use Survos\MeiliBundle\Bridge\EasyAdmin\MeiliEasyAdminDashboardHelper;
use Survos\MeiliBundle\Bridge\EasyAdmin\MeiliEasyAdminMenuFactory;
use Survos\MeiliBundle\Command\ExportCommand;
use Survos\MeiliBundle\Command\IterateIndexesCommand;
use Survos\MeiliBundle\Command\MeiliMcpTestCommand;
use Survos\MeiliBundle\Command\MeilEstimatorCommand;
use Survos\MeiliBundle\Command\MeiliFlushFileCommand;
use Survos\MeiliBundle\Command\MeiliRegistrySyncCommand;
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
use Survos\MeiliBundle\Controller\MeiliFileProxyController;
use Survos\MeiliBundle\Controller\MeiliProxyController;
use Survos\MeiliBundle\Controller\MeiliRegistryController;
use Survos\MeiliBundle\Controller\SearchController;
use Survos\MeiliBundle\Controller\TemplateController;
use Survos\MeiliBundle\EventListener\DoctrineEventListener;
use Survos\MeiliBundle\Menu\MeiliMenuSubscriber;
use Survos\MeiliBundle\MessageHandler\BatchIndexEntitiesMessageHandler;
use Survos\MeiliBundle\Registry\MeiliRegistry;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Survos\MeiliBundle\Service\IndexFastSyncService;
use Survos\MeiliBundle\Service\IndexNameResolver;
use Survos\MeiliBundle\Service\IndexProducer;
use Survos\MeiliBundle\Service\IndexSyncService;
use Survos\MeiliBundle\Service\CollectionMetadataService;
use Survos\MeiliBundle\Service\ChatWorkspaceAccessKeyService;
use Survos\MeiliBundle\Service\ChatWorkspaceResolver;
use Survos\MeiliBundle\Service\MeiliFieldHeuristic;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliServerKeyService;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\MeiliServiceConfig;
use Survos\MeiliBundle\Service\OpenApiFieldMetadataResolver;
use Survos\MeiliBundle\Service\SettingsService;
use Survos\MeiliBundle\Service\TargetPlanner;
use Survos\MeiliBundle\Service\ResultNormalizer;
use Survos\MeiliBundle\Tool\DescribeCollectionTool;
use Survos\MeiliBundle\Tool\GetDocumentTool;
use Survos\MeiliBundle\Tool\SearchFacetsTool;
use Survos\MeiliBundle\Tool\SearchIndexTool;
use Survos\MeiliBundle\Tool\SimilarDocumentsTool;
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

        // Menu subscriber (only works when tabler-bundle is installed)
        $builder->autowire(MeiliMenuSubscriber::class)
            ->setArgument('$meiliHost', $config['host'])
            ->setAutoconfigured(true)
            ->setPublic(false);

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
            ->setArgument(1, '%kernel.project_dir%')
            ->setPublic(true);

        // Parameters
        $builder->setParameter('survos_meili.entity_dirs', $config['entity_dirs']);
        $builder->setParameter('survos_meili.prefix', $config['meiliPrefix']);
        $builder->setParameter('survos_meili.meili_ui_url', $config['meiliUiUrl'] ?: 'http://127.0.0.1:24900/ins/0');
        $builder->setParameter('survos_meili.pricing', $config['pricing'] ?? []);
        $builder->setParameter('survos_meili.meili_settings', $config['meili_settings'] ?? []);
        $builder->setParameter('survos_meili.file_proxy', $config['file_proxy'] ?? []);
        $builder->setParameter('survos_meili.chat', $config['chat'] ?? []);

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
            MeiliRegistrySyncCommand::class,
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

        // Remove TargetPlanner::class from the foreach loop, then add:

        $builder->autowire(TargetPlanner::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setArgument('$translatableIndex', new Reference(
                'Survos\BabelBundle\Service\TranslatableIndex',
                ContainerInterface::NULL_ON_INVALID_REFERENCE
            ));

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

        foreach ([MeiliProxyController::class, MeiliFileProxyController::class] as $class) {
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

        $builder->autowire(MeiliRegistryController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setAutoconfigured(true)
            ->setPublic(true);

        $builder->register(InstantSearchComponent::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$twig', new Reference('twig'))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$meiliService', new Reference('meili_service'));

        // ResultNormalizer is always registered (lightweight, no external deps).
        $builder->autowire(ResultNormalizer::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(OpenApiFieldMetadataResolver::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(CollectionMetadataService::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(ChatWorkspaceAccessKeyService::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $builder->autowire(ChatWorkspaceResolver::class)
            ->setPublic(true)
            ->setAutoconfigured(true)
            ->setArgument('$chatConfig', '%survos_meili.chat%');

        $builder->autowire(MeiliServerKeyService::class)
            ->setPublic(true)
            ->setAutoconfigured(true);

        // AI agent tools + test command: registered when either symfony/ai-agent or symfony/mcp-bundle is installed.
        if (class_exists(\Symfony\AI\Agent\Toolbox\Attribute\AsTool::class) || class_exists(\Mcp\Capability\Attribute\McpTool::class)) {
            foreach ([
                SearchIndexTool::class,
                GetDocumentTool::class,
                SimilarDocumentsTool::class,
                SearchFacetsTool::class,
                DescribeCollectionTool::class,
            ] as $toolClass) {
                $builder->autowire($toolClass)
                    ->setPublic(true)
                    ->setAutoconfigured(true); // picks up #[AsTool] via symfony/ai-bundle autoconfigure
            }

            // Test command only makes sense when AI agent toolbox is available (it invokes tools directly).
            if (class_exists(\Symfony\AI\Agent\Toolbox\Attribute\AsTool::class)) {
                $builder->autowire(MeiliMcpTestCommand::class)
                    ->setPublic(true)
                    ->setAutoconfigured(true)
                    ->addTag('console.command');
            }
        }
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('core_name')->defaultValue('core')->end()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->scalarNode('meiliUiUrl')
                ->defaultValue('http://127.0.0.1:24900/ins/0')
                ->info('Base URL of the Meilisearch UI (riccox). Used to generate per-index links. Override via MEILI_UI_URL env var.')
            ->end()
            ->scalarNode('host')->defaultValue('%env(default::MEILI_SERVER)%')->end()
            // MEILI_ADMIN_KEY is the server-side write key (never exposed to browser).
            // Falls back to MEILI_API_KEY for backward compatibility with existing .env files.
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

            ->arrayNode('file_proxy')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->booleanNode('allow_hidden')->defaultFalse()->end()
                    ->scalarNode('cache_control')->defaultValue('private, max-age=60')->end()
                    ->arrayNode('roots')
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                ->end()
            ->end()

            // ---------------------------------------------------------------
            // Chat workspace configuration
            // ---------------------------------------------------------------
            ->arrayNode('chat')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('workspaces')
                        ->useAttributeAsKey('name')
                        ->arrayPrototype()
                            ->children()
                                ->scalarNode('source')
                                    ->defaultValue('openAi')
                                    ->info('LLM provider: openAi | azureOpenAi | mistral | gemini | vLlm')
                                ->end()
                                ->scalarNode('apiKey')
                                    ->defaultNull()
                                    ->info('Provider API key (use %env(OPENAI_API_KEY)%)')
                                ->end()
                                ->scalarNode('model')
                                    ->defaultValue('gpt-4o-mini')
                                    ->info('Model sent in each completion request (not stored in workspace settings)')
                                ->end()
                                // Optional provider extras
                                ->scalarNode('baseUrl')->defaultNull()->end()
                                ->scalarNode('orgId')->defaultNull()->end()
                                ->scalarNode('projectId')->defaultNull()->end()
                                ->scalarNode('apiVersion')->defaultNull()->end()
                                ->scalarNode('deploymentId')->defaultNull()->end()
                                ->scalarNode('label')
                                    ->defaultNull()
                                    ->info('Human-readable label used in dynamic prompts (defaults to indexName)')
                                ->end()
                                ->scalarNode('detailUrlPattern')
                                    ->defaultNull()
                                    ->info('URL pattern for item detail pages; use {id} as placeholder e.g. /product/{id}')
                                ->end()
                                ->scalarNode('schemaUrl')
                                    ->defaultNull()
                                    ->info('Optional OpenAPI schema URL used to explain field meanings in collection overview responses')
                                ->end()
                                ->arrayNode('examples')
                                    ->info('Example natural-language queries injected into the searchDescription prompt')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([])
                                ->end()
                                ->arrayNode('prompts')
                                    ->addDefaultsIfNotSet()
                                    ->info('Static prompt overrides — these win over dynamic template rendering')
                                    ->children()
                                        ->scalarNode('system')->defaultNull()->end()
                                        ->scalarNode('searchFilterParam')->defaultNull()->end()
                                        ->scalarNode('searchDescription')->defaultNull()->end()
                                        ->scalarNode('searchQParam')->defaultNull()->end()
                                        ->scalarNode('searchIndexUidParam')
                                            ->defaultNull()
                                            ->info('Pin the index UID — prevents Meilisearch generating a full enum of all indexes, which blows the OpenAI context limit.')
                                        ->end()
                                    ->end()
                                ->end()
                                ->arrayNode('indexes')
                                    ->info('Meilisearch index UIDs this workspace has access to')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([])
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()

            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'SurvosMeiliBundle' => [
                            'is_bundle' => false,
                            'type'      => 'attribute',
                            'dir'       => \dirname(__DIR__) . '/src/Entity',
                            'prefix'    => 'Survos\\MeiliBundle\\Entity',
                            'alias'     => 'SurvosMeiliBundle',
                        ],
                    ],
                ],
            ]);
        }



        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', [
                // Never throw for missing icons — return empty string instead.
                // Apps can override to 'throw' if they want strict mode.
                'aliases' => [
                    // UI chrome
                    'home'      => 'material-symbols:home-outline',
                    'browse'    => 'mdi:list-box-outline',
                    'search'    => 'tabler:search',
                    'meili'     => 'tabler:search',
                    'database'  => 'mdi:database',
                    // actions / hits
                    'json'      => 'si:json-duotone',
                    'bug'       => 'tabler:bug',
                    'external'  => 'tabler:external-link',
                    'open-in-new' => 'mdi:open-in-new',
                    // chat
                    'chat'      => 'mdi:chat-outline',
                    'robot'     => 'mdi:robot-outline',
                    'brain'     => 'fluent:brain-circuit-20-filled',
                    'chat-bubble' => 'heroicons:chat-bubble-left-right',
                    // instant search
                    'instant_search'                 => 'mdi:tag-search-outline',
                    'action.detail'                  => 'mdi:show-outline',
                    'field.text_editor.view_content' => 'mdi:cogs',
                    'filter'    => 'tabler:adjustments-alt',
                    'overview'  => 'oui:nav-overview',
                    'database-search' => 'mdi:database-search',
                    // semantic / linked data
                    'semantic-web' => 'mdi:semantic-web',
                    'semantic'     => 'simple-icons:semanticscholar',
                    'wikidata'     => 'openmoji:wikidata',
                    'website'      => 'fluent-mdl2:website',
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
