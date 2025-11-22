<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Liquid\Template;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yethee\Tiktoken\EncoderProvider;

#[AsCommand('meili:estimate', 'Estimate tokens and cost for Meili embedders using JSONL data')]
final class MeilEstimatorCommand extends MeiliBaseCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Index name (unprefixed Meili index name, e.g. "movies", "book")')]
        string $indexName,
        #[Option('Path to JSONL file (default: data/<index>.jsonl)', name: 'file')]
        ?string $jsonlPath = null,
        #[Option('Maximum number of records to sample from JSONL (0 = all)', name: 'limit')]
        int $limit = 200,
    ): int {
        $io->title(sprintf('Meili embedder estimator — index "%s"', $indexName));

        // Resolve JSONL path: explicit --file wins, else data/<index>.jsonl relative to CWD.
        if ($jsonlPath === null) {
            $jsonlPath = sprintf('data/%s.jsonl', $indexName);
        }

        if (!is_file($jsonlPath)) {
            $io->error(sprintf('JSONL file not found: %s', $jsonlPath));
            return Command::FAILURE;
        }

        // Optional profile for recordCount estimate: data/<index>.jsonl.profile.json
        $profilePath  = $jsonlPath . '.profile.json';
        $totalRecords = null;
        if (is_file($profilePath)) {
            try {
                $profile = json_decode(file_get_contents($profilePath) ?: '{}', true, 512, \JSON_THROW_ON_ERROR);
                $totalRecords = $profile['recordCount'] ?? null;
            } catch (\Throwable) {
                // ignore profile errors; we can fall back to sample-only
            }
        }

        $io->section('Loading Meilisearch index settings…');
        $client   = $this->meili->getMeiliClient();
        $index    = $client->getIndex($this->meili->getPrefixedIndexName($indexName));
        $settings = $index->getSettings();

        $embedderConfigs = $settings['embedders'] ?? [];
        if (!$embedderConfigs) {
            $io->warning('No embedders configured for this index (settings["embedders"] is empty).');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('JSONL: <info>%s</info>', $jsonlPath));
        if ($totalRecords !== null) {
            $io->writeln(sprintf('Total records (from profile): <info>%d</info>', $totalRecords));
        } else {
            $io->writeln('Total records: unknown (profile missing; estimate will be sample-only).');
        }
        $io->newLine();

        // Prepare Liquid templates & token encoders per embedder
        $templates = [];
        $encoders  = [];
        $totals    = []; // embedderName => ['tokens' => int, 'rows' => int, 'model' => string]

        $provider = new EncoderProvider();

        foreach ($embedderConfigs as $embedderName => $embedder) {
            $model        = $embedder['model'] ?? null;
            $docTemplate  = $embedder['documentTemplate'] ?? null;

            if (!$model || !$docTemplate) {
                $io->warning(sprintf('Embedder "%s" missing model or documentTemplate; skipping.', $embedderName));
                continue;
            }

            // Liquid template
            $tpl = new Template();
            $tpl->parse($docTemplate);
            $templates[$embedderName] = $tpl;

            // Tokenizer
            try {
                $encoders[$embedderName] = $provider->getForModel($model);
            } catch (\Throwable $e) {
                $io->warning(sprintf(
                    'Cannot get tokenizer for model "%s" (embedder "%s"): %s',
                    $model,
                    $embedderName,
                    $e->getMessage()
                ));
                continue;
            }

            $totals[$embedderName] = [
                'tokens' => 0,
                'rows'   => 0,
                'model'  => $model,
            ];
        }

        if ($templates === [] || $encoders === []) {
            $io->warning('No usable embedders after filtering (missing templates or tokenizers).');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Sampling JSONL (limit: %s)…', $limit > 0 ? $limit : 'ALL'));

        $fh = fopen($jsonlPath, 'rb');
        if (!$fh) {
            $io->error(sprintf('Unable to open JSONL file: %s', $jsonlPath));
            return Command::FAILURE;
        }

        $sampledRows = 0;
        $lineNumber  = 0;

        try {
            while (($line = fgets($fh)) !== false) {
                $lineNumber++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                try {
                    $doc = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Skipping invalid JSON on line %d: %s', $lineNumber, $e->getMessage()));
                    continue;
                }

                if (!\is_array($doc)) {
                    continue;
                }

                $sampledRows++;

                foreach ($templates as $embedderName => $tpl) {
                    if (!isset($encoders[$embedderName])) {
                        continue;
                    }

                    $encoder = $encoders[$embedderName];

                    // Render Liquid template with the doc
                    $text = $tpl->render(['doc' => $doc]);

                    // Tokenize locally
                    $tokens = $encoder->encode($text);
                    $tokenCount = \count($tokens);

                    $totals[$embedderName]['tokens'] += $tokenCount;
                    $totals[$embedderName]['rows']++;
                }

                if ($limit > 0 && $sampledRows >= $limit) {
                    break;
                }
            }
        } finally {
            fclose($fh);
        }

        if ($sampledRows === 0) {
            $io->warning('No rows sampled from JSONL; nothing to estimate.');
            return Command::SUCCESS;
        }

        $io->section(sprintf(
            'Results (sampled %d rows%s):',
            $sampledRows,
            $totalRecords !== null && $sampledRows < $totalRecords ? sprintf(' of %d', $totalRecords) : ''
        ));

        // Hard-coded pricing — cost per 1M tokens.
        // Later: move this into SurvosMeiliBundle config.
        $costPerMillion = [
            'text-embedding-3-small' => 0.02,  // $0.02 / 1M tokens
            'text-embedding-3-large' => 0.13,  // $0.13 / 1M tokens
            'text-embedding-ada-002' => 0.10,  // legacy approx
        ];

        foreach ($totals as $embedderName => $data) {
            if ($data['rows'] === 0) {
                $io->writeln(sprintf(
                    '<comment>Embedder "%s": no rows processed.</comment>',
                    $embedderName
                ));
                continue;
            }

            $tokens    = $data['tokens'];
            $rows      = $data['rows'];
            $model     = $data['model'];
            $avgTokens = $tokens / $rows;

            $perMillion = $costPerMillion[$model] ?? null;
            $costLabel  = $perMillion !== null
                ? sprintf('$%.4f / 1M tokens', $perMillion)
                : '<comment>(price unknown)</comment>';

            $priceTotal     = null;
            $priceEstimate  = null;
            $estTotalTokens = null;

            if ($perMillion !== null) {
                $costPerToken = $perMillion / 1_000_000;
                $priceTotal   = $tokens * $costPerToken;

                if ($totalRecords !== null && $rows < $totalRecords) {
                    $estTotalTokens = $avgTokens * $totalRecords;
                    $priceEstimate  = $estTotalTokens * $costPerToken;
                }
            }

            $lines = [];
            $lines[] = sprintf(
                '<info>%s</info> (model: <comment>%s</comment>):',
                $embedderName,
                $model
            );
            $lines[] = sprintf(
                '  • tokens: <info>%d</info> over <info>%d</info> rows (avg <info>%.1f</info> tokens/row)',
                $tokens,
                $rows,
                $avgTokens
            );
            $lines[] = sprintf('  • price: %s', $costLabel);

            if ($priceTotal !== null) {
                $lines[] = sprintf(
                    '    - sample cost: <info>$%.4f</info>',
                    $priceTotal
                );
            }

            if ($priceEstimate !== null && $estTotalTokens !== null) {
                $lines[] = sprintf(
                    '    - est. full corpus (%d rows): <info>%d</info> tokens → <info>$%.4f</info>',
                    $totalRecords,
                    (int) \round($estTotalTokens),
                    $priceEstimate
                );
            }

            $io->writeln(implode("\n", $lines));
            $io->newLine();
        }

        $io->success('Estimation complete.');
        return Command::SUCCESS;
    }
}
