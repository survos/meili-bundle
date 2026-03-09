<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Meilisearch\Exceptions\ApiException;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\ResultNormalizer;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function preg_match_all;
use function sort;
use function trim;

/**
 * Symfony AI tool: return facet counts for a query on a Meilisearch index.
 *
 * Useful for letting an agent understand the distribution of values before
 * issuing targeted searches (e.g. which categories contain "laptop").
 *
 * Requires symfony/ai-agent (^0.6).
 * Optionally exposed as an MCP tool when symfony/mcp-bundle is installed.
 */
#[AsTool(
    'meili_search_facets',
    'Return facet distribution counts for a query on a Meilisearch index.',
)]
#[McpTool(name: 'meili_search_facets')]
final class SearchFacetsTool
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly ResultNormalizer $normalizer,
    ) {
    }

    /**
     * @param string      $index            The Meilisearch index UID (e.g. "meili_product").
     * @param string      $query            The full-text search query.
     * @param string      $facetAttributes  Comma-separated filterable attributes to aggregate (e.g. "category,brand").
     *                                      Only attributes configured as filterable on the index are valid.
     *                                      Always returned in availableFilterable so the agent can pick correctly.
     * @param string|null $filters          Optional Meilisearch filter expression.
     */
    public function __invoke(
        string $index,
        string $query,
        string $facetAttributes,
        ?string $filters = null,
    ): string {
        $idx = $this->meiliService->getIndexEndpoint($index);

        // Fetch the index's filterable attributes once so every response tells the
        // agent what is valid — no wasted round-trip when the agent guesses wrong.
        $filterableAttributes = $idx->getFilterableAttributes();
        sort($filterableAttributes);

        $attributes = array_values(array_filter(
            array_map(trim(...), explode(',', $facetAttributes)),
            static fn(string $a) => $a !== '',
        ));

        $searchParams = [
            'limit'  => 0, // we only want facets, not hits
            'facets' => $attributes,
        ];
        if ($filters !== null && $filters !== '') {
            $searchParams['filter'] = $filters;
        }

        try {
            $results = $idx->search($query, $searchParams);
        } catch (ApiException $e) {
            // Meilisearch returns a descriptive message listing valid filterable attributes.
            // Parse and return it as a fallback in case getFilterableAttributes() ever diverges.
            // e.g. "…Available filterable attributes patterns are: `brand, category, rating`"
            $message = $e->getMessage();
            $parsedAvailable = [];
            if (preg_match_all('/`([^`]+)`/', $message, $m) && count($m[1]) > 0) {
                $lastToken = $m[1][count($m[1]) - 1];
                foreach (explode(',', $lastToken) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $parsedAvailable[] = $part;
                    }
                }
            }
            return $this->normalizer->toJson([
                'error'               => 'invalid_facet_attribute',
                'message'             => $message,
                'requested'           => $attributes,
                'availableFilterable' => $filterableAttributes ?: $parsedAvailable,
                'hint'                => 'Retry with one or more of the availableFilterable attributes.',
            ]);
        }

        return $this->normalizer->toJson([
            'index'              => $index,
            'query'              => $query,
            'facetDistribution'  => $results->getFacetDistribution() ?? [],
            'facetStats'         => $results->getFacetStats() ?? [],
            'availableFilterable' => $filterableAttributes,
        ]);
    }
}
