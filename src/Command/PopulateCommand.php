<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Service\IndexProducer;
use Survos\MeiliBundle\Service\TargetPlanner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'meili:populate',
    description: 'Populate Meilisearch from Doctrine entities; locale planning uses registry + Babel metadata when available.'
)]
final class PopulateCommand extends MeiliBaseCommand
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly TargetPlanner $planner,
        private readonly IndexProducer $producer,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Base index name (registry key). If omitted, indexes all registered bases.')]
        ?string $indexName = null,

        #[Option('Entity class (FQCN or short App\\Entity\\X)')]
        ?string $class = null,

        #[Option('Filter as YAML (currently informational)', 'filter')]
        string $filter = '',

        #[Option('Max documents to process (producer-side)', 'limit')]
        ?int $limit = null,

        #[Option("Don't send documents", 'dry')]
        ?bool $dry = null,

        #[Option('Primary key field name in Meili (default: settings primaryKey or id)', 'pk')]
        ?string $pk = null,

        #[Option('Index all registered Meili-managed entities when no class is given', 'all')]
        ?bool $all = null,

        #[Option('Purge existing documents (dangerous)', 'purge')]
        ?bool $purge = null,

        #[Option('Fetch and queue documents for indexing', 'fetch')]
        ?bool $fetch = null,

        #[Option('Batch size for primary-key streaming', 'batch')]
        int $batchSize = 1000,

        #[Option('Create one index per locale when multilingual-for-base is true', 'per-locale')]
        ?bool $perLocale = null,

        #[Option('Comma-separated subset of locales to index', 'only-locales')]
        ?string $onlyLocales = null,

        #[Option('Run synchronously (default true)', 'sync')]
        ?bool $sync = null,

        #[Option('Wait for Meili tasks after each batch when sync is enabled (default true)', 'wait')]
        ?bool $wait = null,

        #[Option('Messenger transport name (legacy; ignored when --sync is true)', 'transport')]
        ?string $transport = null,
    ): int {
        $this->io = $io;
        $this->init();

        $sync ??= true;
        $wait ??= true;
        $fetch ??= true;

        // Default per-locale behavior: on when enabled_locales exists
        $perLocale ??= true;

        $onlyLocalesList = $this->parseLocalesCsv($onlyLocales);
        $filterArray = $filter ? (is_array($parsed = Yaml::parse($filter)) ? $parsed : null) : null;

        $io->note(sprintf(
            'populate: indexName=%s class=%s perLocale=%s onlyLocales=%s sync=%s wait=%s',
            $indexName ?? '<all>',
            $class ?? '<auto>',
            $perLocale ? 'yes' : 'no',
            $onlyLocales ?? '<all>',
            $sync ? 'yes' : 'no',
            $wait ? 'yes' : 'no',
        ));

        // Resolve base keys (UNPREFIXED registry keys)
        $bases = $this->resolveTargets($indexName, $class);
        if ($bases === []) {
            $io->warning('No matching base targets.');
            return Command::SUCCESS;
        }

        // Plan concrete targets (UIDs resolved via IndexNameResolver)
        $targets = $this->planner->targetsForBases($bases, $perLocale, $onlyLocalesList);
        if ($targets === []) {
            $io->warning('No expanded targets after locale planning.');
            return Command::SUCCESS;
        }

        if ($io->isVerbose()) {
            $io->section('Expanded targets');
            foreach ($targets as $t) {
                $io->writeln(sprintf(
                    '- kind=%s base=%s uid=%s class=%s locale=%s',
                    $t->kind,
                    $t->base,
                    $t->uid,
                    $t->class,
                    $t->locale ?? '<none>'
                ));
            }
        }

        $summary = [];

        foreach ($targets as $t) {
            $uid    = $t->uid;
            $base   = $t->base;
            $cls    = $t->class;
            $locale = $t->locale;

            $io->section(sprintf(
                'Indexing %s into %s (kind=%s, locale=%s)',
                $cls,
                $uid,
                $t->kind,
                $locale ?? '<none>'
            ));

            if ($dry || !$fetch) {
                $io->note('Skipping dispatch (dry or fetch disabled).');
                $summary[] = [$uid, $cls, $t->kind, $locale ?? '', 0];
                continue;
            }

            $settings = $this->meili->getIndexSetting($base) ?? [];
            $primaryKeyName = $pk ?: ($settings['primaryKey'] ?? 'id');

            if ($purge) {
                $io->warning(sprintf('Purging all documents from index "%s"', $uid));
                $this->meili->getIndexEndpoint($uid)->deleteAllDocuments();
            }

            $runner = fn() => $this->producer->dispatchTarget(
                $t,
                batchSize: $batchSize,
                limit: $limit,
                sync: $sync,
                wait: $wait,
                transport: $transport,
                primaryKeyName: $primaryKeyName,
            );

            $sent = ($locale && $this->localeContext)
                ? $this->localeContext->run($locale, $runner)
                : $runner();

            $io->success(sprintf('Index "%s": %d ids dispatched.', $uid, $sent));
            $summary[] = [$uid, $cls, $t->kind, $locale ?? '', $sent];
        }

        $io->newLine();
        $io->section('Populate summary');

        $table = new Table($io);
        $table->setHeaders(['Index UID', 'Class', 'Kind', 'Locale', 'Dispatched']);
        foreach ($summary as $row) {
            $table->addRow($row);
        }
        $table->render();

        $io->success('meili:populate complete.');
        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function parseLocalesCsv(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $csv))));
        $parts = array_values(array_unique(array_map('strtolower', $parts)));
        return array_values(array_filter($parts, static fn(string $l) => $l !== ''));
    }
}
