<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\Lingua\Core\Identity\HashUtil;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Service\DoctrinePrimaryKeyStreamer;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
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
    description: 'Populate meili from a doctrine entity; per-locale indexing respects Babel per-class targets when available.'
)]
class PopulateCommand extends MeiliBaseCommand
{
    private SymfonyStyle $io;

    public function __construct(
        protected ?EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly SettingsService $settingsService,
        protected MeiliService $meiliService,
        protected ?LoggerInterface $logger,
        private readonly TextFieldResolver $textFieldResolver,
        protected ResolvedEmbeddersProvider $resolvedEmbeddersProvider,
        protected ?NormalizerInterface $normalizer = null,
        protected ?MeiliPayloadBuilder $payloadBuilder = null,
        private readonly ?TranslatableIndex $translatableIndex = null,
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

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Explicit index base name (defaults to prefix + class shortname)')]
        ?string $indexName = null,
        #[Option('Entity class')] ?string $class = null,
        #[Option('Filter as YAML (e.g., "status: published")')] string $filter = '',
        #[Option('Max documents to process (approx, producer side)', 'limit')] ?int $limit = null,
        #[Option("Don't actually update settings or send docs", 'dry')] ?bool $dry = null,
        #[Option('Primary key field name in Meili (defaults to detected or id)', 'pk')] string $pk = 'id',
        #[Option('Dump the Nth normalized row and exit', 'dump')] ?int $dump = null,
        #[Option('Index all registered Meili-managed entities when no class is given', 'all')] ?bool $all = null,
        #[Option('calculate the cost of the embedders. Works with --dry')] ?bool $cost = null,
        #[Option('Create/Update index settings before indexing', 'update-settings')] ?bool $updateSettings = null,
        #[Option('Purge existing documents')] ?bool $purge = null,
        #[Option('Fetch and queue documents for indexing', 'fetch')] ?bool $fetch = null,
        #[Option('Wait for Meili tasks to complete at the end', 'wait')] ?bool $wait = null,
        #[Option('Messenger transport name (defaults to amqp "meili" via AmqpStamp)', 'p', 'p')] ?string $transport = null,
        #[Option('Batch size for producer primary-key streaming', 'batch')] int $batchSize = 1000,
        #[Option('Create one index per locale (suffix _{locale}); default ON when enabled_locales not empty', 'per-locale')] ?bool $perLocale = null,
        #[Option('run sync, same as -p sync')] ?bool $sync = null,
        #[Option('Comma-separated subset of locales to index (intersection filter)', 'only-locales')] ?string $onlyLocales = null,
    ): int {
        $this->io = $io;

        $sync ??= true;
        $fetch ??= true;
        $wait ??= true;
        $perLocale ??= (\count($this->enabledLocales) > 0);

        // Keep the one useful default note line.
        $io->note(sprintf(
            'populate: indexName=%s class=%s perLocale=%s onlyLocales=%s sync=%s',
            $indexName ?? '<auto>',
            $class ?? '<auto>',
            $perLocale ? 'yes' : 'no',
            $onlyLocales ?? '<all>',
            $sync ? 'yes' : 'no'
        ));

        $filterArray = $filter ? (is_array($parsed = Yaml::parse($filter)) ? $parsed : null) : null;

        if ($class && !class_exists($class)) {
            $guess = "App\\Entity\\$class";
            if (class_exists($guess)) {
                $io->writeln(sprintf('Class "%s" not found, using "%s"', $class, $guess), SymfonyStyle::VERBOSITY_VERBOSE);
                $class = $guess;
            }
        }

        $all ??= true;
        if (!$class && !$all) {
            $io->error('Either a class or --all');
            return Command::FAILURE;
        }

        $onlyLocalesList = $this->parseLocalesCsv($onlyLocales);

        $io->writeln($this->meiliService->isMultiLingual
            ? sprintf('MultiLingual mode: enabledLocales=[%s]', implode(', ', $this->enabledLocales))
            : 'MultiLingual mode: OFF'
        );

        $baseTargets = $this->resolveTargets($indexName, $class);
        if ($baseTargets === []) {
            $io->warning('No matching base targets (check indexName / class filters).');
            return Command::SUCCESS;
        }

        if ($io->isVerbose()) {
            $io->section('Resolved base targets');
            foreach ($baseTargets as $baseUid) {
                $io->writeln(sprintf('- %s', $baseUid));
            }
        }

        $targets = [];
        $policyRows = [];

        foreach ($baseTargets as $baseUid) {
            $settings    = $this->meiliService->getIndexSetting($baseUid);
            $entityClass = $settings['class'];

            $plan = $this->planLocalesForEntity($entityClass, $onlyLocalesList);

            $policyRows[] = [
                $baseUid,
                $entityClass,
                $plan['baseLocale'] ?? '',
                implode(',', $plan['targetLocales']),
                $plan['sourceOfTruth'],
            ];

            // Base index once (source/original). No suffixed source index.
            $targets[] = [
                'uid'    => $baseUid,
                'class'  => $entityClass,
                'locale' => $plan['baseLocale'],
                'base'   => $baseUid,
                'kind'   => 'base',
            ];

            if ($perLocale && $this->meiliService->isMultiLingual) {
                foreach ($plan['targetLocales'] as $loc) {
                    $targets[] = [
                        'uid'    => $this->meiliService->localizedUid($baseUid, $loc),
                        'class'  => $entityClass,
                        'locale' => $loc,
                        'base'   => $baseUid,
                        'kind'   => 'target',
                    ];
                }
            }
        }

        if ($io->isVerbose()) {
            $io->section('Locale policy (per class)');
            $pol = new Table($io);
            $pol->setHeaders(['Base', 'Class', 'src/base', 'targets', 'policy']);
            foreach ($policyRows as $r) {
                $pol->addRow($r);
            }
            $pol->render();

            $io->section('Expanded targets');
            foreach ($targets as $t) {
                $io->writeln(sprintf(
                    '- kind=%s base=%s uid=%s class=%s locale=%s',
                    $t['kind'],
                    $t['base'],
                    $t['uid'],
                    $t['class'],
                    $t['locale'] ?? '<none>'
                ));
            }
        }

        if ($this->meiliService->isMultiLingual && !$this->localeContext) {
            $io->warning('MultiLingual is ON but LocaleContext is not available. Locale scoping will NOT be applied.');
        }

        $summary = [];

        foreach ($targets as $t) {
            $uid    = $t['uid'];
            $cls    = $t['class'];
            $locale = $t['locale'];
            $kind   = $t['kind'];

            if ($io->isVerbose()) {
                $io->section(sprintf('Indexing uid=%s class=%s kind=%s locale=%s', $uid, $cls, $kind, $locale ?? '<none>'));
            } else {
                $io->writeln(sprintf('Indexing %s (%s)%s', $uid, $kind, $locale ? " locale=$locale" : ''));
            }

            $index = $this->meiliService->getOrCreateIndex($uid);

            if ($purge && !$dry) {
                $io->warning(sprintf('Purging all documents from index "%s"', $uid));
                $index->deleteAllDocuments();
            }

            if (!$fetch || $dry) {
                $io->note(sprintf('Skipping fetch/index (fetch=%s, dry=%s) for uid=%s', $fetch ? 'true' : 'false', $dry ? 'true' : 'false', $uid));
                $summary[] = [$uid, $cls, $kind, $locale ?? '', 0];
                continue;
            }

            $runner = function () use ($cls, $index, $batchSize, $uid, $sync, $limit, $filterArray, $dump, $transport, $pk, $locale) {
                return $this->indexClass(
                    class:      $cls,
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
                $stats = $this->localeContext->run($locale, $runner);
            } else {
                if ($locale && !$this->localeContext) {
                    $io->writeln(sprintf('Locale "%s" requested but LocaleContext is missing; indexing without scoping.', $locale), SymfonyStyle::VERBOSITY_VERBOSE);
                }
                $stats = $runner();
            }

            $docs = (int) ($stats['numberOfDocuments'] ?? 0);
            $io->success(sprintf('Index "%s": %d documents processed.', $uid, $docs));
            $summary[] = [$uid, $cls, $kind, $locale ?? '', $docs];
        }

        $io->newLine();
        $io->section('Populate summary');
        $table = new Table($io);
        $table->setHeaders(['Index UID', 'Class', 'Kind', 'Locale', 'Docs']);
        foreach ($summary as $row) {
            $table->addRow($row);
        }
        $table->render();

        $io->success($this->getName().' complete.');
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $onlyLocales optional intersection filter list
     * @return array{baseLocale:?string, targetLocales:list<string>, sourceOfTruth:string}
     */
    private function planLocalesForEntity(string $entityClass, array $onlyLocales): array
    {
        $fallbackEnabled = array_values(array_unique(array_filter(array_map(
            static fn($l) => HashUtil::normalizeLocale((string) $l),
            $this->enabledLocales
        ))));

        $baseLocale = HashUtil::normalizeLocale($this->defaultLocale);
        $targets    = $fallbackEnabled;
        $policy     = 'enabled_locales';

        if ($this->translatableIndex && $this->translatableIndex->has($entityClass)) {
            $src = $this->translatableIndex->sourceLocaleFor($entityClass);
            $baseLocale = HashUtil::normalizeLocale($src ?: $this->defaultLocale);

            $targets = $this->translatableIndex->effectiveTargetLocalesFor($entityClass, $fallbackEnabled);
            $policy  = 'babel(targetLocalesFor)';
        }

        $targets = array_values(array_unique(array_filter(array_map(
            static fn($l) => HashUtil::normalizeLocale((string) $l),
            $targets
        ))));

        // Never index source locale as a per-locale target
        $targets = array_values(array_filter($targets, fn(string $l) => $l !== '' && $l !== $baseLocale));

        // Intersect with --only-locales if provided
        if ($onlyLocales !== []) {
            $only = array_flip($onlyLocales);
            $targets = array_values(array_filter($targets, static fn(string $l) => isset($only[$l])));
        }

        return [
            'baseLocale'    => $this->localeContext ? $baseLocale : null,
            'targetLocales' => $targets,
            'sourceOfTruth' => $policy,
        ];
    }

    /** @return list<string> */
    private function parseLocalesCsv(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $csv))));
        $parts = array_values(array_unique(array_map([HashUtil::class, 'normalizeLocale'], $parts)));
        return array_values(array_filter($parts, static fn(string $l) => $l !== ''));
    }

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

        $stamps = [];
        if ($transport) {
            $stamps[] = new TransportNamesStamp($transport);
        }

        if ($limit && ($batchSize > $limit)) {
            $batchSize = $limit;
        }

        $primaryKey ??= $index->getPrimaryKey();

        $streamer  = new DoctrinePrimaryKeyStreamer($this->entityManager, $class);
        $generator = $streamer->stream($batchSize);

        $approx = $this->meiliService->getApproxCount($class)
            ?: $this->entityManager->getRepository($class)->count();

        $progressBar = new ProgressBar($this->io, $approx);
        $progressBar->start();

        $this->io->title(sprintf('Indexing %s into %s (locale=%s)', $class, $indexName, $locale ?? '<none>'));

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

            $this->messageBus->dispatch($message, $stamps);

            if ($max && ($progressBar->getProgress() >= $max)) {
                break;
            }
        }

        $progressBar->finish();

        return ['numberOfDocuments' => $progressBar->getProgress()];
    }
}
