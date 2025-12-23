<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Registry\MeiliRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('meili:registry', 'Inspect the compiled Meili index registry (including Pixie-derived indexes)')]
final class MeiliRegistryCommand
{
    public function __construct(private readonly MeiliRegistry $registry) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Filter index names by substring')] ?string $filter = null,
        #[Option('Dump full JSON settings for matching indexes')] bool $dump = false,
    ): int {
        $rows = [];

        foreach ($this->registry->summary($filter) as $name => $info) {
            $schema = $info['schema'] ?? [];
            $rows[] = [
                $name,
                $this->registry->uidFor($name),
                $info['class'] ?? '',
                $info['primaryKey'] ?? '',
                isset($schema['filterableAttributes']) ? count((array)$schema['filterableAttributes']) : 0,
                isset($schema['sortableAttributes']) ? count((array)$schema['sortableAttributes']) : 0,
                isset($schema['searchableAttributes']) ? count((array)$schema['searchableAttributes']) : 0,
                is_array($info['facets'] ?? null) ? count($info['facets']) : 0,
            ];

            if ($dump) {
                $io->section($name);
                $io->writeln(json_encode($this->registry->settingsFor($name), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        $io->title('Meili registry (compiled)');
        $io->table(['raw','uid','class','pk','filterable','sortable','searchable','facets'], $rows);
        $io->writeln('Count: ' . count($rows));

        return Command::SUCCESS;
    }
}
