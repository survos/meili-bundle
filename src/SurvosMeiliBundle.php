<?php

namespace Survos\MeiliBundle;

use ReflectionClass;
use Survos\CoreBundle\HasAssetMapperInterface;
use Survos\CoreBundle\Traits\HasAssetMapperTrait;
use Survos\InspectionBundle\Services\InspectionService;
use Survos\MeiliBundle\Api\Filter\MultiFieldSearchFilter;
use Survos\MeiliBundle\Command\FastSyncIndexesCommand;
use Survos\MeiliBundle\Command\IterateIndexesCommand;
use Survos\MeiliBundle\Command\SyncIndexesCommand;
use Survos\MeiliBundle\Components\InstantSearchComponent;
use Survos\MeiliBundle\Command\CreateCommand;
use Survos\MeiliBundle\Command\IndexCommand;
use Survos\MeiliBundle\Command\ListCommand;
use Survos\MeiliBundle\Command\SettingsCommand;
use Survos\MeiliBundle\Controller\MeiliController;
use Survos\MeiliBundle\Controller\SearchController;
use Survos\MeiliBundle\EventListener\DoctrineEventListener;
use Survos\MeiliBundle\Filter\MeiliSearch\AbstractSearchFilter;
use Survos\MeiliBundle\MessageHandler\BatchIndexEntitiesMessageHandler;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Survos\MeiliBundle\Service\IndexFastSyncService;
use Survos\MeiliBundle\Service\IndexSyncService;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Survos\MeiliBundle\Util\BabelLocaleScope;
use Survos\MeiliBundle\Util\TextFieldResolver;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
            ->tag('doctrine.event_listener', ['event' => 'prePersist'])
            ->tag('doctrine.event_listener', ['event' => 'postPersist']);

        foreach ([SettingsService::class, AbstractSearchFilter::class, MultiFieldSearchFilter::class] as $class) {
            $builder->autowire($class)
                ->setPublic(true);
        }

        $builder->autowire(MeiliService::class)
            ->setArgument('$config', $config)
            ->setArgument('$meiliHost', $config['host'])
            ->setArgument('$adminKey', $config['apiKey'])
            ->setArgument('$searchKey', $config['searchKey'])
            ->setArgument('$httpClient', new Reference('httplug.http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$bag', new Reference('parameter_bag'))
            ->setArgument('$indexedEntities', []) // placeholder; will be replaced in process()
            ->setAutowired(true)
            ->setPublic(true)
            ->setAutoconfigured(true);

        $container->services()->alias('meili_service', MeiliService::class);

        foreach ([IndexCommand::class, SettingsCommand::class,
//                     FastSyncIndexesCommand::class,
//                     SyncIndexesCommand::class,
                     IterateIndexesCommand::class,
                     ListCommand::class, CreateCommand::class] as $class) {
            $builder->autowire($class)
                ->setPublic(true)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }

        foreach ([IndexSyncService::class, IndexFastSyncService::class, TextFieldResolver::class, BatchIndexEntitiesMessageHandler::class] as $class) {
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

        $builder->register(InstantSearchComponent::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$twig', new Reference('twig'))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$inspectionService', new Reference(InspectionService::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
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
            ->scalarNode('searchKey')->defaultValue('%env(default::MEILI_SEARCH_KEY)%')->end()
            ->scalarNode('meiliPrefix')->defaultValue('%env(default::MEILI_PREFIX)%')->end()
            ->booleanNode('passLocale')->defaultValue(false)->end()
            ->integerNode('maxValuesPerFacet')->defaultValue(1000)->end()
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
    }

    /**
     * CompilerPass logic: Find all entities with #[MeiliIndex] and inject them into MeiliService
     */
    public function process(ContainerBuilder $container): void
    {
        $attributeClass = MeiliIndex::class; // adjust if different
        // @todo: recurse?  Allow bundle entities?
        // use             $metas = $this->entityManager->getMetadataFactory()->getAllMetadata(); to get the doctrine-managed classes?
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
                && preg_match('/class\s+([^\s]+)/i', $contents, $classMatch)) {
                $classes[] = $nsMatch[1] . '\\' . $classMatch[1];
            }
        }

        return $classes;
    }
}
