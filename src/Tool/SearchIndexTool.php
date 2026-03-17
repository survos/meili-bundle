<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\ResultNormalizer;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesInterface;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesTrait;
use Symfony\AI\Agent\Toolbox\Source\Source;

use function array_key_exists;
use function count;
use function max;
use function min;
use function sprintf;

/**
 * Symfony AI tool: search a single Meilisearch index.
 *
 * Supports keyword-only search (default) and hybrid search (vector + keyword)
 * when an embedder name is supplied.
 *
 * Requires symfony/ai-agent (^0.6).
 * Optionally exposed as an MCP tool when symfony/mcp-bundle is installed.
 */
#[AsTool(
    'meili_search_index',
    'Search a single Meilisearch index and return matching documents as JSON. Supports hybrid (semantic + keyword) search when an embedder name is provided.',
)]
#[McpTool(name: 'meili_search_index')]
final class SearchIndexTool implements HasSourcesInterface
{
    use HasSourcesTrait;

    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly ResultNormalizer $normalizer,
    ) {
    }

    /**
     * @param string      $index         The Meilisearch index UID to search (e.g. "meili_product").
     * @param string      $query         The full-text search query.
     * @param int         $limit         Maximum number of results to return (1–50).
     * @param string|null $filters       Optional Meilisearch filter expression (e.g. "brand = Nike AND price < 100").
     * @param string|null $embedder      Embedder name for hybrid search (e.g. "product"). When supplied, semantic search is blended in.
     * @param float       $semanticRatio Blend ratio 0.0 (pure keyword) – 1.0 (pure vector). Only used when $embedder is set.
     */
    public function __invoke(
        string $index,
        string $query,
        int $limit = 8,
        ?string $filters = null,
        ?string $embedder = null,
        float $semanticRatio = 0.5,
    ): string {
        $searchParams = ['limit' => max(1, min(50, $limit))];
        if ($filters !== null && $filters !== '') {
            $searchParams['filter'] = $filters;
        }
        if ($embedder !== null && $embedder !== '') {
            $semanticRatio = max(0.0, min(1.0, $semanticRatio));
            $searchParams['hybrid'] = [
                'embedder'      => $embedder,
                'semanticRatio' => $semanticRatio,
            ];
        }

        $results = $this->meiliService
            ->getIndexEndpoint($index)
            ->search($query, $searchParams);

        $hits = $this->normalizer->normalizeHits($results->getHits());

        foreach ($hits as $hit) {
            $label = $this->normalizer->labelFor($hit);
            $this->addSource(new Source(
                $label,
                sprintf('meili://%s/%s', $index, $hit['id'] ?? $hit[array_key_first($hit)] ?? '?'),
                $this->normalizer->toJson($hit),
            ));
        }

        return $this->normalizer->toJson([
            'index'      => $index,
            'query'      => $query,
            // getTotalHits() is null for hybrid/offset-limit pagination; fall back to estimatedTotalHits.
            'totalHits'  => $results->getTotalHits() ?? $results->getEstimatedTotalHits() ?? count($hits),
            'hits'       => $hits,
        ]);
    }
}
