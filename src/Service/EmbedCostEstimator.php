<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * Estimates embedding cost by tokenizing sample texts and applying per-1K-token pricing.
 * Tokenizer is injected so you can plug in OpenAI-compatible estimators (e.g. openai/tokenizer).
 */
final class EmbedCostEstimator
{
    /**
     * @param callable(string): int $tokenizerFn returns token count for a single text
     * @param float $pricePer1kTokens USD per 1K input tokens (embeddings usually charge on input tokens only)
     */
    public function __construct(
        private readonly callable $tokenizerFn,
        private readonly float $pricePer1kTokens = 0.02, // sensible default; override via DI or CLI
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param iterable<string> $samples
     * @return array{samples:int,totalTokens:int,avgTokens:float,estimatedCost:float}
     */
    public function estimate(iterable $samples): array
    {
        $count = 0;
        $total = 0;

        foreach ($samples as $s) {
            $tokens = (int) call_user_func($this->tokenizerFn, (string) $s);
            $total += $tokens;
            $count++;
        }

        $avg = $count ? $total / $count : 0.0;
        $usd = ($total / 1000.0) * $this->pricePer1kTokens;

        $this->logger?->info(sprintf('Embedding estimate: %d samples, %d tokens total (avg %.1f), ~$%.4f.',
            $count, $total, $avg, $usd
        ));

        return [
            'samples'       => $count,
            'totalTokens'   => $total,
            'avgTokens'     => $avg,
            'estimatedCost' => round($usd, 4),
        ];
    }
}
