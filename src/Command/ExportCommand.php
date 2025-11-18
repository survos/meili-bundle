<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Meilisearch\Contracts\DocumentsQuery;
use Psr\Log\LoggerInterface;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'meili:export',
    description: 'Export a Meilisearch index to .jsonl (optionally .gz) using batched getDocuments().',
)]
class ExportCommand
{
    public function __construct(
        private MeiliService    $meiliService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Meilisearch index name (without bundle prefix).')]
        ?string $indexName = null,

        #[Option('Override Meilisearch host (optional, normally from config).')]
        ?string $host = null,

        #[Option('Override Meilisearch API key (optional, normally from config).')]
        ?string $apiKey = null,

        #[Option('Output file (.jsonl or .jsonl.gz). Defaults to <index>.jsonl[.gz].')]
        ?string $output = null,

        #[Option('Directory to store export file(s). Will be created if missing.')]
        ?string $dir = null,

        #[Option('Batch size when fetching documents.')]
        int $batchSize = 1_000,

        #[Option('Compress output with gzip (or infer from .gz extension).')]
        bool $gzip = false,
    ): int {

        if (!class_exists(JsonlWriter::class)) {
            $io->error("jsonl-bundle is not installed.\n\ncomposer req survos/jsonl-bundle");
            return Command::FAILURE;
        }
        if (!$indexName) {
            $io->error('You must provide an index name.');
            return Command::FAILURE;
        }

        // Normalize index name via MeiliService (prefix, env, etc.)
        $prefixedIndexName = $this->meiliService->getPrefixedIndexName($indexName);

        // Resolve base directory (for archival, git, etc.)
        $baseDir = $dir ? rtrim($dir, DIRECTORY_SEPARATOR) : getcwd();
        if ($baseDir === '' || $baseDir === false) {
            $io->error('Could not determine a working directory.');
            return Command::FAILURE;
        }

        if ($dir) {
            if (!is_dir($baseDir)) {
                if (!@mkdir($baseDir, 0o777, true) && !is_dir($baseDir)) {
                    $io->error(sprintf('Failed to create directory "%s".', $baseDir));
                    return Command::FAILURE;
                }
                $io->writeln(sprintf('Created archive directory: <info>%s</info>', $baseDir));
            }
        }

        // Resolve filename + gzip behavior
        $defaultBase = $prefixedIndexName . '.jsonl';

        if ($output !== null) {
            // If user gave an explicit output path, we respect it and ignore --dir as a prefix.
            $filename = $output;
            if ($dir) {
                $io->note('--dir is ignored because an explicit --output path was provided.');
            }
        } else {
            $filename = $defaultBase;
            if ($dir) {
                $filename = $baseDir . DIRECTORY_SEPARATOR . $filename;
            }
        }

        $inferGzip = str_ends_with($filename, '.gz');
        if ($gzip && !$inferGzip) {
            $filename .= '.gz';
        }

        $io->title('Meilisearch index export');
        $io->writeln(sprintf('Index: <info>%s</info>', $prefixedIndexName));
        $io->writeln(sprintf('File:  <info>%s</info>', $filename));
        $io->newLine();

        // Get client/index (host/apiKey overrides are currently ignored by MeiliService
        // unless you add support there; we just log them for now).
        if ($host || $apiKey) {
            $this->logger->info('meili:export called with host/apiKey override', [
                'host'   => $host,
                'apiKey' => $apiKey ? '[provided]' : null,
            ]);
        }

        try {
            $client = $this->meiliService->getMeiliClient();
            $index  = $client->getIndex($prefixedIndexName);
        } catch (\Throwable $e) {
            $io->error(sprintf(
                "Unable to get Meilisearch index '%s': %s",
                $prefixedIndexName,
                $e->getMessage()
            ));
            return Command::FAILURE;
        }

        // Determine total docs (for progress bar)
        $total = null;
        try {
            $stats = $index->stats();
            // PHP client usually returns an array-like structure
            if (is_array($stats)) {
                $total = $stats['numberOfDocuments'] ?? $stats['number_of_documents'] ?? null;
            } elseif (is_object($stats) && property_exists($stats, 'numberOfDocuments')) {
                $total = $stats->numberOfDocuments;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch index stats for export', [
                'index'   => $prefixedIndexName,
                'message' => $e->getMessage(),
            ]);
        }

        if ($total === null) {
            $io->warning('Could not determine numberOfDocuments from Meilisearch stats; progress bar will be indeterminate.');
        }

        $batchSize = max(1, $batchSize);

        // Ensure parent directory exists for filename if user passed a path via --output
        $parentDir = dirname($filename);
        if ($parentDir && $parentDir !== '.' && !is_dir($parentDir)) {
            if (!@mkdir($parentDir, 0o777, true) && !is_dir($parentDir)) {
                $io->error(sprintf('Failed to create directory "%s" for output file.', $parentDir));
                return Command::FAILURE;
            }
            $io->writeln(sprintf('Created directory for output file: <info>%s</info>', $parentDir));
        }

        // Open JsonlWriter (it should handle .gz automatically based on extension)
        $writer = JsonlWriter::open($filename);

        $io->section('Exporting documents…');

        $progress = $total !== null
            ? new ProgressBar($io, (int) $total)
            : new ProgressBar($io);

        $progress->setFormat('verbose');
        $progress->start();

        $offset  = 0;
        $written = 0;
        $done    = false;

        try {
            while (!$done) {
                $params = (new DocumentsQuery())
                    ->setLimit($batchSize)
                    ->setOffset($offset);

                $batch = $index->getDocuments($params);

                // Meilisearch PHP client may return an array *or* an object with getResults()
                if (is_array($batch)) {
                    $documents = $batch;
                } elseif (is_object($batch) && method_exists($batch, 'getResults')) {
                    $documents = $batch->getResults();
                } else {
                    throw new \RuntimeException(sprintf(
                        'Unexpected getDocuments() return type: %s',
                        get_debug_type($batch)
                    ));
                }

                $count = \count($documents);
                if ($count === 0) {
                    $done = true;
                    break;
                }

                foreach ($documents as $document) {
                    if (\is_array($document) && \array_key_exists('rp', $document)) {
                        // Strip application-specific field
                        unset($document['rp']);
                    }

                    $writer->write($document);
                    $written++;
                    $progress->advance();
                }

                $offset += $batchSize;

                // Safety: stop if total known and we've reached it
                if ($total !== null && $written >= $total) {
                    $done = true;
                }
            }

            $progress->finish();
            $io->newLine(2);

            $writer->close();

            $io->success(sprintf(
                'Exported %d document%s from index "%s" to %s',
                $written,
                $written === 1 ? '' : 's',
                $prefixedIndexName,
                $filename
            ));

            $io->writeln('');
            $io->writeln(sprintf(
                'You can now run <info>cd %s && git init</info> to start archiving these exports.',
                $dir ? $baseDir : $parentDir
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $progress->clear();
            $writer->close();

            $this->logger->error('Error during Meilisearch index export', [
                'index'   => $prefixedIndexName,
                'file'    => $filename,
                'message' => $e->getMessage(),
            ]);

            $io->error(sprintf(
                'Export failed for index "%s": %s',
                $prefixedIndexName,
                $e->getMessage()
            ));

            return Command::FAILURE;
        }
    }
}
