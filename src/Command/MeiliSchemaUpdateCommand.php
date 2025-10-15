<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Meilisearch\Client;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('meili:schema:update', 'Update Meilisearch index settings from compiler-pass schema')]
final class MeiliSchemaUpdateCommand extends Command
{
    /** @param array<string,array<string,mixed>> $indexSettings (indexName => settings)
     *  @param array<string,string>               $indexEntities (indexName => FQCN)
     */
    public function __construct(
        private readonly MeiliService $meili,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Dump settings without applying', name: 'dump')]
        bool $dumpSettings = false,

        #[Option('Wait for task to complete')]
        bool $wait = false,

        #[Option('Apply changes (send updateSettings)')]
        bool $force = false,

        #[Option('Cancel tasks and delete index before applying')]
        bool $reset = false,

        #[Option('Filter by index name')]
        ?string $index = null,

        #[Option('Filter by FQCN or short class name')]
        ?string $class = null,
    ): int {
        if ($reset) {
            $force = true;
        }
        $wait ??= true;

        $targets = $this->resolveTargets($index, $class);
        if ($targets === []) {
            $io->warning('No matching indexes. Use --index or --class to filter. or --all?');
            return Command::SUCCESS;
        }

        if ($dumpSettings) {
            foreach ($targets as $name) {
                $io->section(sprintf('Index "%s"', $name));
                $settings = $this->meili->getRawIndexSetting($name);
//                dd($settings, $name);
                $index = $this->meili->getIndex($this->meili->getPrefixedIndexName($name));
                // is update different than create?
//                $task = $index->updateSettings($settings['schema']);
//                dump($task);
                $io->writeln(json_encode($settings['schema'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));


            }
            if (!$force) {
                return Command::SUCCESS;
            }
        }

        foreach ($targets as $name) {
            $io->section(sprintf('Processing index "%s"', $name));
//            $uid = $this->prefixed($name);

            $settings = $this->meili->getRawIndexSetting($name);
            $index = $this->meili->getIndex($this->meili->getPrefixedIndexName($name));
            $pending = $this->pendingTasks($name);
            if (!$reset && $pending > 0) {
                $io->error(sprintf('Index "%s" has %d pending tasks. Re-run with --reset.', $index->getUid(), $pending));
                return Command::FAILURE;
            }

            if ($reset) {
                $io->warning('Reset: canceling tasks and deleting index…');
                $this->cancelTasks($index->getUid(), $io);
                $this->deleteIndexIfExists($index->getUid(), $io);
            }

            if (!$force) {
                $io->note('Dry run (no --force): settings NOT applied.');
                continue;
            }

            $task = $index->updateSettings($settings['schema']);
            $embedders = $settings['embedders'] ?? [];
            if ($embedders !== []) {
                dd($embedders);
                // resolve api keys from params if provided as parameter names
                foreach ($embedders as $name => &$cfg) {
                    if (!empty($cfg['apiKeyParameter'])) {
                        $paramName = $cfg['apiKeyParameter'];
                        $cfg['apiKey'] = $this->params->get($paramName) ?? getenv($paramName) ?? null;
                        unset($cfg['apiKeyParameter']);
                    }
                }
                // Wrap into the structure Meilisearch expects: [ name => [ ...config... ] ]
                $task = $index->updateEmbedders($embedders);
                $res  = $this->meiliService->waitForTask($task);
                if (($res['status'] ?? null) !== 'succeeded') {
                    throw new \RuntimeException('Embedders update failed: '.json_encode($res));
                }
            }


            $io->writeln(json_encode($settings['schema'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $io->writeln(sprintf('updateSettings taskUid=%s', (string)($task['taskUid'] ?? 'unknown')));

            try {
                $this->meili->waitForTask($task['taskUid'] ?? 0, $index, true, 50);
                $io->success('Settings updated.');
            } catch (\Throwable) {
                $io->warning('Settings update task still in progress.');
            }
        }

        return Command::SUCCESS;
    }

    /** @return string[] */
    private function resolveTargets(?string $index, ?string $class): array
    {
        $classes = array_keys($this->meili->indexedByClass());
        $names = array_keys($this->meili->getRawIndexSettings());
        if ($index) {
            $names = array_filter($names, static fn($n) => $n === $index);
        }
        if ($class) {
            $names = array_values(array_filter($classes, function ($n) use ($class) {
                return ($this->indexEntities[$n] ?? null) === $class
                    || str_ends_with($this->indexEntities[$n] ?? '', '\\' . ltrim($class, '\\'));
            }));
        }
        return $names;
    }

        private function deleteIndexIfExists(string $uid, SymfonyStyle $io): void
    {
        try {
            $task = $this->meili->index($uid)->delete();
            $io->writeln(sprintf('delete index taskUid=%s', (string)($task['taskUid'] ?? 'unknown')));
            try { $this->meili->waitForTask($task['taskUid'] ?? 0, 2000, 50); } catch (\Throwable) {}
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            if ($e->getCode() === 404) {
                $io->writeln('Index did not exist.');
                return;
            }
            throw $e;
        }
    }

    private function pendingTasks(string $uid): int
    {
        $resp = $this->meili->getTasks($uid,  MeiliTaskStatus::ACTIVE);

        return \count($resp['results'] ?? []);
    }

    private function cancelTasks(string $uid, SymfonyStyle $io): void
    {
        $resp = $this->meili->cancelTasks(['indexUids' => [$uid]]);
        $io->writeln(sprintf('cancelTasks taskUid=%s', (string)($resp['taskUid'] ?? 'unknown')));
        try { $this->meili->waitForTask($resp['taskUid'] ?? 0, 2000, 50); } catch (\Throwable) {}
    }

}
