<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('meili:schema:validate', 'Compare compiled schema with Meilisearch remote settings')]
final class MeiliSchemaValidateCommand extends Command
{
    /** @param array<string,array<string,mixed>> $indexSettings (indexName => settings)
     *  @param array<string,string>               $indexEntities (indexName => FQCN)
     */
    public function __construct(
        private readonly MeiliService $meili,
//        private readonly array $indexSettings,
//        private readonly array $indexEntities,
//        private readonly ?string $prefix = null, // e.g. '%survos_meili.prefix%'
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Output JSON diff')]
        bool $json = false,

        #[Option('Do not fail if index has pending tasks')]
        bool $allowPending = false,

        #[Option('Filter by index name')]
        ?string $index = null,

        #[Option('Filter by FQCN or short class name', name: 'class')]
        ?string $class = null,
    ): int {
        $targets = $this->resolveTargets($index, $class);
        if ($targets === []) {
            $io->warning('No matching indexes. Use --index or --class to filter.');
            return Command::SUCCESS;
        }

        $hadError = false;
        $results = [];

        foreach ($targets as $name) {
            $uid = $this->prefixed($name);
            $io->section(sprintf('Index "%s" (uid: %s)', $name, $uid));

            // Check pending tasks first
            $pending = $this->pendingTasks($uid);
            if ($pending > 0) {
                $msg = sprintf('%d pending tasks detected on "%s".', $pending, $uid);
                if ($allowPending) {
                    $io->warning($msg . ' (allowed by --allow-pending)');
                } else {
                    $io->error($msg . ' Use meili:schema:update --reset or re-run with --allow-pending.');
                    $hadError = true;
                    // still continue to show diffs
                }
            }

            $desired = $this->buildMeiliSettings($name);
            $remote  = $this->fetchRemoteSettings($uid);

            $diff = $this->diffSettings($desired, $remote);
            $results[$name] = $diff;

            if ($json) {
                $io->writeln(json_encode(['index' => $name, 'uid' => $uid, 'diff' => $diff], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                continue;
            }

            if ($diff['equals']) {
                $io->success('Remote settings match compiled schema.');
            } else {
                if ($diff['missing'] || $diff['extra'] || $diff['changed']) {
                    $io->warning('Settings differ from compiled schema:');
                }

                if ($diff['missing']) {
                    $io->text(' • Missing on remote:');
                    foreach ($diff['missing'] as $k => $v) {
                        $io->writeln(sprintf('   - %s: %s', $k, json_encode($v, JSON_UNESCAPED_SLASHES)));
                    }
                }
                if ($diff['extra']) {
                    $io->text(' • Present remotely but not in compiled schema:');
                    foreach ($diff['extra'] as $k => $v) {
                        $io->writeln(sprintf('   - %s: %s', $k, json_encode($v, JSON_UNESCAPED_SLASHES)));
                    }
                }
                if ($diff['changed']) {
                    $io->text(' • Changed values:');
                    foreach ($diff['changed'] as $k => $pair) {
                        $io->writeln(sprintf(
                            '   - %s: expected %s, remote %s',
                            $k,
                            json_encode($pair['expected'], JSON_UNESCAPED_SLASHES),
                            json_encode($pair['remote'], JSON_UNESCAPED_SLASHES),
                        ));
                    }
                }
            }
        }

        return $hadError ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return string[] */
    private function resolveTargets(?string $index, ?string $class): array
    {
        $names = array_keys($this->meili->indexSettings);

        if ($index) {
            $names = array_values(array_filter($names, static fn($n) => $n === $index));
        }
        if ($class) {
            $names = array_values(array_filter($names, function ($n) use ($class) {
                return ($this->indexEntities[$n] ?? null) === $class
                    || str_ends_with($this->indexEntities[$n] ?? '', '\\' . ltrim($class, '\\'));
            }));
        }
        return $names;
    }

    private function prefixed(string $indexName): string
    {
        $p = trim((string) ($this->prefix ?? ''), '._-');
        return $p !== '' ? $p . '_' . $indexName : $indexName;
    }

    private function pendingTasks(string $uid): int
    {
        $resp = $this->meili->getMeiliClient()->getTasks(
            new TasksQuery()->setUids([$uid])->setStatuses( ['enqueued', 'processing'])->setLimit(1000)
        );
        dd($resp);
        return \count($resp['results'] ?? []);
    }

    /** @return array<string,mixed> */
    private function fetchRemoteSettings(string $uid): array
    {
        try {
            return $this->meili->index($uid)->getSettings();
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            if ($e->getCode() === 404) {
                // Non-existent index => all settings "missing" remotely
                return [];
            }
            throw $e;
        }
    }

    /** @return array{equals:bool, missing:array<string,mixed>, extra:array<string,mixed>, changed:array<string,array{expected:mixed,remote:mixed}>} */
    private function diffSettings(array $expected, array $remote): array
    {
        // Only compare core keys we set; ignore other Meili keys unless present in desired
        $keys = array_keys($expected);
        $missing = [];
        $changed = [];

        foreach ($keys as $k) {
            $e = $expected[$k] ?? null;
            $r = $remote[$k] ?? null;

            // normalize ordering for array-valued keys (filterable/sortable typically order-agnostic)
            if (is_array($e)) {
                $norm = static fn($x) => is_array($x) ? array_values(array_unique($x)) : $x;
                $eN = $norm($e);
                $rN = $norm($r);
            } else {
                $eN = $e; $rN = $r;
            }

            if ($r === null) {
                $missing[$k] = $e;
            } elseif ($eN !== $rN) {
                $changed[$k] = ['expected' => $e, 'remote' => $r];
            }
        }

        // extras: keys that exist remotely but we don't manage (only count if they collide with our managed keys set)
        $extra = [];
        foreach ($remote as $k => $v) {
            if (!\array_key_exists($k, $expected) && \in_array($k, [
                'displayedAttributes','filterableAttributes','sortableAttributes','searchableAttributes'
            ], true)) {
                $extra[$k] = $v;
            }
        }

        $equals = ($missing === [] && $changed === [] && $extra === []);
        return compact('equals','missing','extra','changed');
    }

    /** Translate compiled settings → Meilisearch payload. */
    private function buildMeiliSettings(string $indexName): array
    {
        $cfg = $this->indexSettings[$indexName] ?? [];

        $display    = $cfg['display']    ?? ['*'];
        $filterable = $cfg['filterable'] ?? [];
        $sortable   = $cfg['sortable']   ?? [];
        $searchable = $cfg['searchable'] ?? [];

        return array_filter([
            'displayedAttributes'  => $display ?: ['*'],
            'filterableAttributes' => array_values(array_unique($filterable)),
            'sortableAttributes'   => array_values(array_unique($sortable)),
            'searchableAttributes' => $searchable ?: ['*'],
        ], static fn($v) => $v !== null);
    }
}
