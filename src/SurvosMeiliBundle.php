<?php

namespace Survos\MeiliBundle;

use Psr\Log\LoggerAwareInterface;
use ReflectionClass;
use Survos\CoreBundle\HasAssetMapperInterface;
use Survos\CoreBundle\Traits\HasAssetMapperTrait;
use Survos\MeiliBundle\Api\Filter\MultiFieldSearchFilter;
use Survos\MeiliBundle\Command\ExportCommand;
use Survos\MeiliBundle\Command\FastSyncIndexesCommand;
use Survos\MeiliBundle\Command\IterateIndexesCommand;
use Survos\MeiliBundle\Command\MeilEstimatorCommand;
use Survos\MeiliBundle\Command\MeiliFlushFileCommand;
use Survos\MeiliBundle\Command\MeiliSchemaCreateCommand;
use Survos\MeiliBundle\Command\MeiliSchemaUpdateCommand;
use Survos\MeiliBundle\Command\MeiliSchemaValidateCommand;
use Survos\MeiliBundle\Command\MeiliSuggestSettingsCommand;
use Survos\MeiliBundle\Command\SyncIndexesCommand;
use Survos\MeiliBundle\Compiler\MeiliIndexPass;
use Survos\MeiliBundle\Components\InstantSearchComponent;
use Survos\MeiliBundle\Command\CreateCommand;
use Survos\MeiliBundle\Command\PopulateCommand;
use Survos\MeiliBundle\Command\SettingsCommand;
use Survos\MeiliBundle\Controller\MeiliAdminController;
use Survos\MeiliBundle\Controller\MeiliController;
use Survos\MeiliBundle\Controller\MeiliProxyController;
use Survos\MeiliBundle\Controller\SearchController;
use Survos\MeiliBundle\Controller\TemplateController;
use Survos\MeiliBundle\EventListener\DoctrineEventListener;
use Survos\MeiliBundle\Filter\MeiliSearch\AbstractSearchFilter;
use Survos\MeiliBundle\MessageHandler\BatchIndexEntitiesMessageHandler;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Survos\MeiliBundle\Service\IndexFastSyncService;
use Survos\MeiliBundle\Service\IndexSyncService;
use Survos\MeiliBundle\Service\MeiliFieldHeuristic;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Survos\MeiliBundle\Util\EmbedderConfig;
use Survos\MeiliBundle\Util\ResolvedEmbeddersProvider;
use Survos\MeiliBundle\Util\TextFieldResolver;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Survos\MeiliBundle\Bridge\EasyAdmin\MeiliEasyAdminMenuFactory;

class SurvosMeiliBundle extends AbstractBundle implements HasAssetMapperInterface, CompilerPassInterface
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
            $builder->autowire($class)
                ->setPublic(true);
        }

        $builder->autowire(ResolvedEmbeddersProvider::class)
            ->setArgument(0, $config['embedders'] ?? [])
            ->setPublic(true);

        $builder->setParameter('survos_meili.entity_dirs', $config['entity_dirs']);
        $builder->setParameter('survos_meili.prefix', $config['meiliPrefix']);
        $builder->setParameter('survos_meili.pricing', $config['pricing'] ?? []);
        $builder->setParameter('survos_meili.meili_settings', $config['meili_settings'] ?? []);

        $builder->autowire(MeiliService::class)
            ->setArgument('$config', $config)
            ->setArgument('$meiliHost', $config['host'])
            ->setArgument('$adminKey', $config['apiKey'])
            ->setArgument('$searchKey', $config['searchKey'])
            ->setArgument('$httpClient', new Reference('httplug.http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$bag', new Reference('parameter_bag'))
            ->setArgument('$indexedEntities', []) // placeholder; replaced in process()
            ->setAutowired(true)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $container->services()->alias('meili_service', MeiliService::class);

        foreach ([PopulateCommand::class,
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
                ->addTag('console.command');
        }

        foreach ([IndexSyncService::class,
                     MeiliNdjsonUploader::class,
                     MeiliFieldHeuristic::class,
                     SyncIndexesCommand::class,
                     IndexFastSyncService::class,
                     TextFieldResolver::class,
                     BatchIndexEntitiesMessageHandler::class] as $class) {
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
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        foreach ([MeiliProxyController::class] as $class) {
            $builder->autowire($class)
                ->addTag('container.service_subscriber')
                ->addTag('controller.service_arguments')
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        $builder->autowire(MeiliController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setArgument('$chartBuilder', new Reference('chartjs.builder', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setAutoconfigured(true)
            ->setPublic(true);

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
            ->booleanNode('passLocale')->defaultFalse()->end()
            ->integerNode('maxValuesPerFacet')->defaultValue(1000)->end()

            // Optional external tools, e.g. OpenAI playground, dashboards, etc.
            ->arrayNode('tools')
            ->arrayPrototype()
            ->children()
            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('url')->isRequired()->cannotBeEmpty()->end()
            ->end()
            ->end()
            ->end()

            // Embedders: a named config that MeiliService + ResolvedEmbeddersProvider will use.
            ->arrayNode('embedders')
            ->useAttributeAsKey('name')
            ->arrayPrototype()
            ->children()
            ->scalarNode('source')->isRequired()->cannotBeEmpty()->end()      // e.g. 'openAi'
            ->scalarNode('model')->isRequired()->cannotBeEmpty()->end()       // e.g. 'text-embedding-3-small'
            ->scalarNode('apiKey')->defaultNull()->end()                      // e.g. '%env(OPENAI_API_KEY)%'
            ->scalarNode('for')->defaultNull()->end()                         // optional FQCN (e.g. App\Entity\Product)
            ->scalarNode('template')->defaultNull()->end()                    // optional path to Liquid template
            ->integerNode('documentTemplateMaxBytes')->defaultValue(4096)->end()
            ->integerNode('maxTokensPerDoc')
            ->defaultNull()
            ->info('Optional hint for expected max tokens per doc for cost estimation / guard rails.')
            ->end()
            ->arrayNode('examples')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->end()
            ->end()
            ->end()

            // Pricing configuration for cost estimator.
            // Cost per 1M tokens, keyed by embedding model.
            ->arrayNode('pricing')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('embedders')
            ->useAttributeAsKey('model')
            ->scalarPrototype()->end()
            ->defaultValue([
                'text-embedding-3-small' => 0.02,  // $0.02 / 1M tokens
                'text-embedding-3-large' => 0.13,  // $0.13 / 1M tokens
                'text-embedding-ada-002' => 0.10,  // legacy approx
            ])
            ->end()
            ->end()
            ->end()

            // Allow passing default Meili settings (e.g. typoTolerance)
            // through bundle config to SettingsService/SettingsCommand.
            ->arrayNode('meili_settings')
            ->addDefaultsIfNotSet()
            ->children()
            // This is intentionally loose; structure mirrors Meilisearch settings.
            ->arrayNode('typoTolerance')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->integerNode('oneTypo')->defaultValue(5)->end()
            ->integerNode('twoTypos')->defaultValue(9)->end()
            ->arrayNode('disableOnWords')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->arrayNode('disableOnAttributes')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
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

            // allow multiple directories to be scanned for #[MeiliIndex]
            ->arrayNode('entity_dirs')
            ->scalarPrototype()->end()
            ->defaultValue(['%kernel.project_dir%/src/Entity'])
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

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass($this);
        $container->addCompilerPass(new MeiliIndexPass());
    }

    public function process(ContainerBuilder $container): void
    {
        $attributeClass = MeiliIndex::class;

        $entityDir = $container->getParameter('kernel.project_dir') . '/src/Entity';
        $indexedClasses = [];
        foreach ($this->getClassesInDirectory($entityDir) as $class) {
            assert(class_exists($class), "Missing $class in $entityDir");
            $ref = new ReflectionClass($class);
            if ($ref->getAttributes($attributeClass)) {
                $indexedClasses[] = $class;
            }
        }

        $container->setParameter('meili.indexed_entities', $indexedClasses);

        if ($container->hasDefinition(MeiliService::class)) {
            $def = $container->getDefinition(MeiliService::class);
            $def->setArgument('$indexedEntities', $indexedClasses);
        }

        if (0) {
            $container->registerForAutoconfiguration(LoggerAwareInterface::class)
                ->addMethodCall('setLogger', [new Reference('logger')]);
        }
    }

    private function getClassesInDirectory(string $dir): array
    {
        $classes = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (str_ends_with($file->getBasename('.' . $file->getExtension()), 'Interface')) {
                continue;
            }
            $contents = file_get_contents($file->getRealPath());
            if (preg_match('/namespace\s+([^;]+);/i', $contents, $nsMatch)
                && preg_match('/^class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $classMatch)) {
                $classes[] = ($class = $nsMatch[1] . '\\' . $classMatch[1]);
                assert(class_exists($class), "missing class $class in " . $contents);
            }
        }

        return $classes;
    }
}
