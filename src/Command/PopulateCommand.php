<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Service\DoctrinePrimaryKeyStreamer;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Survos\MeiliBundle\Util\BabelLocaleScope;
use Survos\MeiliBundle\Util\EmbedderConfig;
use Survos\MeiliBundle\Util\ResolvedEmbeddersProvider;
use Survos\MeiliBundle\Util\TextFieldResolver;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'meili:populate',
//    aliases: ['meili:index'],
    description: 'Populate meili from a doctrine entity, could be per-locale'
)]
class PopulateCommand extends MeiliBaseCommand
{
    private SymfonyStyle $io;

    public function __construct(
        protected ?EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private SettingsService $settingsService,
        private MeiliService $meiliService,
        protected ?LoggerInterface $logger,
        private TextFieldResolver $textFieldResolver,
        protected ResolvedEmbeddersProvider $resolvedEmbeddersProvider,
//        #[Autowire('%kernel.project_dir%')]
//        protected readonly string $projectDir,

        protected ?NormalizerInterface $normalizer=null,
        protected ?MeiliPayloadBuilder $payloadBuilder=null,

        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales = [],
        #[Autowire('%kernel.default_locale%')] private string $defaultLocale = 'en',
        private ?BabelLocaleScope $localeScope = null, // optional (no-op if Babel not installed)
    ) {

        parent::__construct($meiliService,$resolvedEmbeddersProvider, $entityManager, $this->normalizer, $this->payloadBuilder);
    }

    /**
     * Return fields configured as filterable (a.k.a. "browsable") in SettingsService.
     *
     * @param array $settings
     * @return array<string>
     */
    public function getFilterableAttributes(array $settings): array
    {
        return $this->settingsService->getFieldsWithAttribute($settings, 'browsable');
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Explicit index base name (defaults to prefix + class shortname)')]
        ?string $indexName = null,

        #[Option('Entity class')]
        ?string $class = null,

        #[Option('Filter as YAML (e.g., "status: published")')]
        string $filter = '',

        #[Option('Max documents to process (approx, producer side)', 'limit')]
        ?int $limit = null,

        #[Option("Don't actually update settings or send docs", 'dry')]
        ?bool $dry = null,

        #[Option('Primary key field name in Meili (defaults to detected or id)', 'pk')]
        string $pk = 'id',

        #[Option('Dump the Nth normalized row and exit', 'dump')]
        ?int $dump = null,

        #[Option('Index all registered Meili-managed entities when no class is given', 'all')]
        ?bool $all = null,

        #[Option('calculate the cost of the embedders.  Works with --dry')]
        ?bool $cost = null,

        #[Option('Create/Update index settings before indexing', 'update-settings')]
        ?bool $updateSettings = null,

        #[Option('Delete and recreate index settings (implies update-settings)', 'reset')]
        ?bool $reset = null,

        #[Option('Fetch and queue documents for indexing', 'fetch')]
        ?bool $fetch = null,

        #[Option('Wait for Meili tasks to complete at the end', 'wait')]
        ?bool $wait = null,

        #[Option('Messenger transport name (defaults to amqp "meili" via AmqpStamp)', 'p', 'p')]
        ?string $transport = null,

        #[Option('Batch size for producer primary-key streaming', 'batch')]
        int $batchSize = 1000,

        // Locale behavior
        #[Option('Create one index per locale (suffix _{locale}); default ON', 'per-locale')]
        ?bool $perLocale = null,

        #[Option('run sync, same as -p sync')]
        ?bool $sync = null,

        #[Option('Comma-separated subset of locales to index (default: enabled_locales)', 'only-locales')]
        ?string $onlyLocales = null,

    ): int {
        $this->io = $io;

        $sync ??= true;
        // default behaviors
        $fetch ??= true; // unless explicitly turned off
        $perLocale ??= (count($this->enabledLocales) > 0);


        // optional filter
        $filterArray = $filter ? (is_array($parsed = Yaml::parse($filter)) ? $parsed : null) : null;

        // normalize class
        if ($class && !class_exists($class)) {
            $class = "App\\Entity\\$class";
        }
        $all ??= true;
        if (!$class && !$all) {
            $io->error('Either a class or filter or --all');
            return Command::FAILURE;
        }

        // Locales to handle
        $locales = $onlyLocales
            ? array_values(array_filter(array_map('trim', explode(',', $onlyLocales))))
            : $this->enabledLocales;

        $targets = $this->resolveTargets($indexName, $class, $perLocale ? $locales : null);



        foreach ($targets as $uId) {
            {
                $settings = $this->meiliService->getIndexSetting($uId);
                    $this->io->title($uId);

                    if ($reset) {
                        if ($dry) {
                            $io->error('you cannot have both --reset and --dry');
                            return Command::FAILURE;
                        }
                        $task = $this->meiliService->reset($uId);
                        $updateSettings = true;
                    }

                    $index = $this->meiliService->getOrCreateIndex($uId, autoCreate: false);
                    if (!$index) {
                        $this->io->error("Index {$uId} not found, run meili:settings to create");
                        return Command::FAILURE;
                    }

                    if ($fetch && !$dry) {
                        $entityClass = $settings['class'];
                        // Producer side: stream primary keys in batches; consumer will load+normalize with the same locale
                        $runner = function () use ($entityClass,
                            $index, $batchSize, $uId,
                            $sync,
                            $limit, $filterArray, $dump, $transport, $pk,
                        ) {
                            return $this->indexClass(
                                class: $entityClass,
                                index: $index,
                                batchSize: $batchSize,
                                indexName: $uId,
                                limit: $limit ?? 0,
                                filter: $filterArray,
                                dump: $dump,
                                primaryKey: $index->getPrimaryKey(),
                                max: $limit,
                                transport: $sync ? 'sync' : $transport,
                                pk: $pk,
                                locale: null, // $languageForIndex
                            );
                        };

                        // If Babel is present, scope locale even while producing (mostly no-op here but consistent)
                        $stats = $this->localeScope
                            ? $this->localeScope->withLocale($languageForIndex, $runner)
                            : $runner();

                        $this->io->success($uId . ' Document count: ' . $stats['numberOfDocuments']);

                        if ($wait) {
                            $this->meiliService->waitUntilFinished($index);
                        }
                    }

                    if ($this->io->isVeryVerbose()) {
                        $stats = $index->stats();
                        $this->io->title("$uId stats");
                        $this->io->write(json_encode($stats, JSON_PRETTY_PRINT));
                    }

                    if ($this->io->isVerbose()) {
                        $this->io->title("$uId settings");
                        $this->io->write(json_encode($index->getSettings(), JSON_PRETTY_PRINT));
                    }

                    $this->io->success($this->getName() . ' ' . $entityClass . ' finished indexing to ' . $uId);
                }
            }
        $this->io->success($this->getName() . ' complete.');
        return self::SUCCESS;
    }


    /**
     * Producer: stream entity primary keys and dispatch BatchIndexEntitiesMessage
     * with the locale & target index name.
     */
    private function indexClass(
        string  $class,
        Indexes $index,
        int $batchSize,
        ?string $indexName = null,
        int $limit = 0,
        ?array $filter = [],
        ?int $dump = null,
        ?string $primaryKey = null,
        ?int $max = null,
        ?string $transport = null,
        ?string $subdomain = null,
        ?string $pk = null,
        ?string $locale = null,
    ): array {
        $stamps = [];
        if ($transport) {
            $stamps[] = new TransportNamesStamp($transport);
        } else {
            // default to amqp queue named "meili"
            $stamps[] = new AmqpStamp('meili');
        }
        if ($limit && ($batchSize > $limit)) {
            $batchSize = $limit;
        }

        $primaryKey ??= $index->getPrimaryKey();

        // Stream ids from Doctrine
        $streamer  = new DoctrinePrimaryKeyStreamer($this->entityManager, $class);
        $generator = $streamer->stream($batchSize);

        // progress estimate
        $approx = $this->meiliService->getApproxCount($class) ?: $this->entityManager->getRepository($class)->count();
        $progressBar = new ProgressBar($this->io, $approx);
        $progressBar->start();
        $this->io->title($class);

        foreach ($generator as $chunk) {
            $progressBar->advance(\count($chunk));
            $message = new BatchIndexEntitiesMessage(
                $class,
                entityData: $chunk,
                reload: true,
                transport: $transport,
                primaryKeyName: $primaryKey,
                locale: $locale,
                indexName: $indexName,
            );
            // Pass locale + index name so consumer writes to correct index & scopes Babel before normalization
            $this->messageBus->dispatch(
                $message,
                $stamps
            );

            if ($max && ($progressBar->getProgress() >= $max)) {
                break;
            }
        }

        $progressBar->finish();

        return ['numberOfDocuments' => $progressBar->getProgress()];
    }

    public function getProcessBar(int $total = 0): ProgressBar
    {
        $progressBar = new ProgressBar($this->io, $total);
        if ($total) {
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% -- %message%');
        } else {
            $progressBar->setFormat(' %current% [%bar%] %elapsed:6s% %memory:6s% -- %message%');
        }
        return $progressBar;
    }

    public function showIndexSettings(Indexes $index): void
    {
        if ($this->io->isVeryVerbose()) {
            $table=  new Table($this->io);
            $table->setHeaders(['Attributes','Values']);
            try {
                $settings = $index->getSettings();
                foreach ($settings as $var => $val) {
                    if (is_array($val)) {
                        $table->addRow([str_replace('Attributes', '', $var), join("\n", $val)]);
                    }
                }
            } catch (\Exception) {
                // ignore if index missing or server error
            }
            $table->render();
            $this->io->writeln($index->getUid());
        }
    }
}
