<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Service\DoctrinePrimaryKeyStreamer;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'meili:populate',
    description: 'Populate meili from a doctrine entity, could be per-locale'
)]
class PopulateCommand extends MeiliBaseCommand
{
    private SymfonyStyle $io;

    public function __construct(
        protected ?EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private SettingsService $settingsService,
        protected MeiliService $meiliService,
        protected ?LoggerInterface $logger,
        private TextFieldResolver $textFieldResolver,
        protected ResolvedEmbeddersProvider $resolvedEmbeddersProvider,
        protected ?NormalizerInterface $normalizer = null,
        protected ?MeiliPayloadBuilder $payloadBuilder = null,
        ?LocaleContext $localeContext = null,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales = [],
        #[Autowire('%kernel.default_locale%')] private string $defaultLocale = 'en',
        #[Autowire('%kernel.project_dir%')] ?string $projectDir = null,
    ) {
        parent::__construct(
            localeContext: $localeContext,
            meili: $meiliService,
            embeddersProvider: $resolvedEmbeddersProvider,
            entityManager: $entityManager,
            normalizer: $this->normalizer,
            payloadBuilder: $this->payloadBuilder,
            projectDir: $projectDir,
        );
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

        #[Option('Purge existing documents')]
        ?bool $purge = null,

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
        $fetch ??= true;
        $wait ??= true;
        $perLocale ??= (\count($this->enabledLocales) > 0);

        $io->note(sprintf(
            'populate: indexName=%s class=%s perLocale=%s onlyLocales=%s sync=%s',
            $indexName ?? '<auto>',
            $class ?? '<auto>',
            $perLocale ? 'yes' : 'no',
            $onlyLocales ?? '<all>',
            $sync ? 'yes' : 'no'
        ));

        // optional filter
        $filterArray = $filter ? (is_array($parsed = Yaml::parse($filter)) ? $parsed : null) : null;

        // normalize class
        if ($class && !class_exists($class)) {
            $guess = "App\\Entity\\$class";
            if (class_exists($guess)) {
                $io->writeln(sprintf('Class "%s" not found, using "%s"', $class, $guess), SymfonyStyle::VERBOSITY_VERBOSE);
                $class = $guess;
            }
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

        if ($this->meiliService->isMultiLingual) {
            $io->writeln(sprintf(
                'MultiLingual mode: enabledLocales=[%s]',
                implode(', ', $this->enabledLocales)
            ));
        } else {
            $io->writeln('MultiLingual mode: OFF');
        }

        $baseTargets = $this->resolveTargets($indexName, $class);

        if ($baseTargets === []) {
            $io->warning('No matching base targets (check indexName / class filters).');
            return Command::SUCCESS;
        }

        $io->section('Resolved base targets');
        foreach ($baseTargets as $baseUid) {
            $io->writeln(sprintf('- %s', $baseUid));
        }

        $targets = [];
        foreach ($baseTargets as $baseUid) {
            $settings    = $this->meiliService->getIndexSetting($baseUid);
            $entityClass = $settings['class'];

            if ($perLocale && $this->meiliService->isMultiLingual && $locales) {
                foreach ($locales as $locale) {
                    $localizedUid = $this->meiliService->localizedUid($baseUid, $locale);
                    $targets[] = [
                        'uid'    => $localizedUid,
                        'class'  => $entityClass,
                        'locale' => $locale,
                        'base'   => $baseUid,
                    ];
                }
            } else {
                $targets[] = [
                    'uid'    => $baseUid,
                    'class'  => $entityClass,
                    'locale' => null,
                    'base'   => $baseUid,
                ];
            }
        }

        $io->section('Expanded targets (per-locale)');
        foreach ($targets as $t) {
            $io->writeln(sprintf(
                '- base=%s uid=%s class=%s locale=%s',
                $t['base'],
                $t['uid'],
                $t['class'],
                $t['locale'] ?? '<default>'
            ));
        }

        if ($this->meiliService->isMultiLingual && !$this->localeContext) {
            $io->warning('MultiLingual is ON but LocaleContext is not available (no BabelBundle?). Locale scoping will NOT be applied.');
        }

        foreach ($targets as $t) {
            $uid    = $t['uid'];
            $class  = $t['class'];
            $locale = $t['locale'];

            $io->section(sprintf(
                'Indexing target uid=%s class=%s locale=%s',
                $uid,
                $class,
                $locale ?? '<default>'
            ));

            $index = $this->meiliService->getOrCreateIndex($uid);

            if ($purge && !$dry) {
                $io->warning(sprintf('Purging all documents from index "%s"', $uid));
                $index->deleteAllDocuments();
            }

            if (!$fetch || $dry) {
                $io->note(sprintf(
                    'Skipping fetch/index (fetch=%s, dry=%s) for uid=%s',
                    $fetch ? 'true' : 'false',
                    $dry ? 'true' : 'false',
                    $uid
                ));
                continue;
            }

            // capture locals for closure
            $runner = function () use (
                $class,
                $index,
                $batchSize,
                $uid,
                $sync,
                $limit,
                $filterArray,
                $dump,
                $transport,
                $pk,
                $locale
            ) {
                $this->io->writeln(sprintf(
                    'runner(): class=%s uid=%s locale=%s batchSize=%d limit=%s transport=%s',
                    $class,
                    $uid,
                    $locale ?? '<default>',
                    $batchSize,
                    $limit === null ? '<null>' : (string) $limit,
                    $sync ? 'sync' : ($transport ?? '<null>')
                ), SymfonyStyle::VERBOSITY_VERBOSE);

                return $this->indexClass(
                    class:      $class,
                    index:      $index,
                    batchSize:  $batchSize,
                    indexName:  $uid,
                    limit:      $limit ?? 0,
                    filter:     $filterArray,
                    dump:       $dump,
                    primaryKey: $index->getPrimaryKey(),
                    max:        $limit,
                    transport:  $sync ? 'sync' : $transport,
                    pk:         $pk,
                    locale:     $locale,
                );
            };

            if ($locale && $this->localeContext) {
                $io->writeln(sprintf(
                    'Using LocaleContext->run(%s) for uid=%s',
                    $locale,
                    $uid
                ));
                $stats = $this->localeContext->run($locale, $runner);
            } else {
                if ($locale && !$this->localeContext) {
                    $io->warning(sprintf(
                        'Locale "%s" requested but LocaleContext is missing; running runner() without Babel scoping.',
                        $locale
                    ));
                    dump($this->localeContext);
                    assert(false);
                }
                $stats = $runner();
            }

            $io->success(sprintf(
                'Index "%s" locale=%s: %d documents processed.',
                $uid,
                $locale ?? '<default>',
                $stats['numberOfDocuments'] ?? 0
            ));
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
        $indexName ??= $index->getUid();

        $this->io->writeln(sprintf(
            'indexClass(): class=%s index=%s locale=%s batchSize=%d limit=%d transport=%s',
            $class,
            $indexName,
            $locale ?? '<default>',
            $batchSize,
            $limit,
            $transport ?? '<null>'
        ), SymfonyStyle::VERBOSITY_VERBOSE);

        $stamps = [];
        if ($transport) {
            $stamps[] = new TransportNamesStamp($transport);
        }

        if ($limit && ($batchSize > $limit)) {
            $batchSize = $limit;
        }

        $primaryKey ??= $index->getPrimaryKey();

        // Stream ids from Doctrine
        $streamer  = new DoctrinePrimaryKeyStreamer($this->entityManager, $class);
        $generator = $streamer->stream($batchSize);

        // progress estimate
        $approx = $this->meiliService->getApproxCount($class)
            ?: $this->entityManager->getRepository($class)->count();
        $progressBar = new ProgressBar($this->io, $approx);
        $progressBar->start();
        $this->io->title(sprintf('Indexing %s into %s (locale=%s)', $class, $indexName, $locale ?? '<default>'));

        foreach ($generator as $chunk) {
            $count = \count($chunk);
            $progressBar->advance($count);

            if ($this->io->isVeryVerbose()) {
                $this->io->writeln(sprintf(
                    'Dispatching chunk of %d ids to index=%s locale=%s',
                    $count,
                    $indexName,
                    $locale ?? '<default>'
                ));
            }

            $message = new BatchIndexEntitiesMessage(
                $class,
                entityData: $chunk,
                reload: true,
                transport: $transport,
                primaryKeyName: $primaryKey,
                locale: $locale,
                indexName: $indexName,
            );

            $this->messageBus->dispatch($message, $stamps);

            if ($max && ($progressBar->getProgress() >= $max)) {
                if ($this->io->isVeryVerbose()) {
                    $this->io->writeln(sprintf(
                        'Reached max=%d, stopping producer loop for index=%s locale=%s',
                        $max,
                        $indexName,
                        $locale ?? '<default>'
                    ));
                }
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
            $table = new Table($this->io);
            $table->setHeaders(['Attributes', 'Values']);
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
