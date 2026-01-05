<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('meili:settings:update', 'Update Meilisearch index settings from compiler-pass schema')]
final class MeiliSchemaUpdateCommand extends MeiliBaseCommand
{
    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Filter by index name, without prefix')]
        ?string $indexName = null,

        #[Option('Dump settings without applying', name: 'dump')]
        bool $dumpSettings = false,

        #[Option('Wait for task to complete')]
        bool $wait = false,

        #[Option('Apply changes (send updateSettings)')]
        bool $force = false,

        #[Option('Cancel tasks and delete index before applying')]
        bool $reset = false,

        #[Option('Filter by FQCN or short class name')]
        ?string $class = null,
    ): int {
        $this->init();

        $rawSettings = $this->meili->getRawIndexSettings(); // keyed by raw index name
        $bases = $this->resolveTargets($indexName, $class);

        if ($bases === []) {
            $io->warning('No matching indexes.');
            return Command::SUCCESS;
        }

        foreach ($bases as $base) {
            $settings = $rawSettings[$base] ?? null;
            if (!$settings) {
                $io->warning(sprintf('No compiler-pass settings found for "%s".', $base));
                continue;
            }

            $schema = $settings['schema'] ?? [];
            $facets = $settings['facets'] ?? [];

            $isMl = $this->indexNameResolver->isMultiLingual();
            $locales = $isMl ? ($this->localeContext?->getEnabled() ?? []) : [null];

            foreach ($locales as $locale) {
                $uid = $this->indexNameResolver->uidFor($base, $locale);

                $io->section(sprintf('Processing %s (locale=%s)', $uid, (string) $locale));

                // Always show what would be applied when very verbose,
                // or when --dump is requested.
                if ($dumpSettings || $io->isVeryVerbose()) {
                    $io->writeln("Schema payload (updateSettings):");
                    $io->writeln(json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    if ($io->isDebug()) { // -vvv
                        $io->writeln("\nFacet UI metadata (compiler pass):");
                        $io->writeln(json_encode($facets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }

                if ($dumpSettings) {
                    // dump implies no network calls
                    continue;
                }

                if ($reset) {
                    $this->meili->reset($uid);
                }

                if (!$force) {
                    $io->warning(sprintf('Use --force to apply settings to %s', $uid));
                    continue;
                }

                // No "ensure" semantics: just get endpoint and enqueue updateSettings.
                // Meilisearch will auto-create index if needed when applying settings.
                $index = $this->meili->getIndexEndpoint($uid);

                $task = $index->updateSettings($schema);
                $taskUid = $task->getTaskUid();

                $io->writeln(sprintf('Dispatched updateSettings taskUid=%s', (string) $taskUid));

                if ($wait) {
                    // Your existing code uses SDK wait() when desired.
                    // Only do this when explicitly requested.
                    try {
                        $task = $task->wait();
                    } catch (\Throwable) {
                        // ignore; waiting is best-effort
                    }
                }
            }
        }

        return Command::SUCCESS;
    }
}
