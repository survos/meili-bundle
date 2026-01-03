<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Registry\MeiliRegistry;
use Survos\MeiliBundle\Service\IndexNameResolver;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'meili:registry:report',
    description: 'Report base Meili index registry entries (optionally probe server).'
)]
final class MeiliRegistryReportCommand extends Command
{
    public function __construct(
        private readonly MeiliRegistry $registry,
        private readonly MeiliService $meili,
        private readonly IndexNameResolver $resolver,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('base', 'Optional substring/pattern to limit results (matches base name).')]
        ?string $base = null,
        #[Option('Locale to report against when registry does not declare a source locale.', shortcut: 'l')]
        string $locale = 'en',
    ): int {
        $base = $base !== null ? trim($base) : null;
        if ($base === '') {
            $base = null;
        }
        $locale = strtolower(trim($locale)) ?: 'en';

        $io->title('Meili registry report');
        $io->writeln(sprintf('Locale: <info>%s</info>', $locale));
        $io->writeln(sprintf('Multilingual: <info>%s</info>', $this->resolver->isMultiLingual() ? 'yes' : 'no'));
        $io->newLine();

        $rows = [];
        foreach ($this->registry->names() as $baseName) {
            if ($base && !str_contains($baseName, $base)) {
                continue;
            }

            $cfg = $this->registry->settingsFor($baseName) ?? [];
            $ui  = (array)($cfg['ui'] ?? []);
            $label = (string)($ui['label'] ?? '');
            if ($label === '') {
                $class = (string)($cfg['class'] ?? $this->registry->classFor($baseName) ?? '');
                $label = $class ? $this->shortClass($class) : '';
            }

            $loc = $this->resolver->localesFor($baseName, $locale);
            $isMlFor = $this->resolver->isMultiLingualFor($baseName, $locale);

            $rows[] = [
                $baseName,
                $label,
                $isMlFor ? 'yes' : 'no',
                $loc['source'],
                $loc['targets'] ? implode(',', $loc['targets']) : '',
            ];
        }

        $io->table(['Base name', 'Label', 'Multilingual', 'Locale', 'Target locales'], $rows);

        if ($io->isVerbose()) {
            $io->section('Resolved raw index names (verbose)');

            foreach ($this->registry->names() as $baseName) {
                if ($base && !str_contains($baseName, $base)) {
                    continue;
                }

                $loc = $this->resolver->localesFor($baseName, $locale);
                $isMlFor = $this->resolver->isMultiLingualFor($baseName, $locale);

                $rawNames = [];
                foreach ($loc['all'] as $l) {
                    $rawNames[] = $this->resolver->rawFor($baseName, $l, $isMlFor);
                }

                $io->writeln(sprintf('<info>%s</info>: %s', $baseName, implode(', ', $rawNames)));
            }
        }

        if ($io->isVeryVerbose()) {
            $io->section('Server index status (very verbose)');

            $serverRows = [];
            foreach ($this->registry->names() as $baseName) {
                if ($base && !str_contains($baseName, $base)) {
                    continue;
                }

                $loc = $this->resolver->localesFor($baseName, $locale);
                $isMlFor = $this->resolver->isMultiLingualFor($baseName, $locale);

                foreach ($loc['all'] as $l) {
                    $raw = $this->resolver->rawFor($baseName, $l, $isMlFor);
                    $uid = $this->resolver->uidForRaw($raw);

                    [$exists, $createdAt, $updatedAt] = $this->probeServerIndex($uid);

                    $serverRows[] = [
                        $baseName,
                        $l,
                        $raw,
                        $uid,
                        $exists ? 'yes' : 'no',
                        $createdAt ?? '',
                        $updatedAt ?? '',
                    ];
                }
            }

            $io->table(['Base', 'Locale', 'Raw', 'UID', 'Exists', 'Created', 'Updated'], $serverRows);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{0:bool,1:?string,2:?string}
     */
    private function probeServerIndex(string $uid): array
    {
        try {
            $index = $this->meili->getIndexEndpoint($uid);

            $createdAt = null;
            $updatedAt = null;

            try {
                if (method_exists($index, 'fetchInfo')) {
                    $info = $index->fetchInfo();
                } elseif (method_exists($index, 'getRawInfo')) {
                    $info = $index->getRawInfo();
                } elseif (method_exists($index, 'getInfo')) {
                    $info = $index->getInfo();
                } else {
                    $info = null;
                }

                if (is_array($info)) {
                    $createdAt = isset($info['createdAt']) ? (string)$info['createdAt'] : null;
                    $updatedAt = isset($info['updatedAt']) ? (string)$info['updatedAt'] : null;
                } elseif (is_object($info)) {
                    $createdAt = property_exists($info, 'createdAt') ? (string)$info->createdAt : null;
                    $updatedAt = property_exists($info, 'updatedAt') ? (string)$info->updatedAt : null;
                }
            } catch (\Throwable) {
                // ignore
            }

            try {
                $index->stats();
            } catch (\Throwable) {
                return [false, null, null];
            }

            return [true, $createdAt, $updatedAt];
        } catch (\Throwable) {
            return [false, null, null];
        }
    }

    private function shortClass(string $fqcn): string
    {
        $p = strrpos($fqcn, '\\');
        return $p === false ? $fqcn : substr($fqcn, $p + 1);
    }
}
