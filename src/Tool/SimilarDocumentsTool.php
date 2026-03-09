<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Meilisearch\Contracts\SimilarDocumentsQuery;
use Meilisearch\Exceptions\ApiException;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\ResultNormalizer;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesInterface;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesTrait;
use Symfony\AI\Agent\Toolbox\Source\Source;

use function max;
use function min;
use function sprintf;

/**
 * Symfony AI tool: find documents similar to a given document using Meilisearch's
 * native /similar endpoint (requires an embedder configured on the index).
 *
 * Requires symfony/ai-agent (^0.6).
 * Optionally exposed as an MCP tool when symfony/mcp-bundle is installed.
 */
#[AsTool(
    'meili_similar_documents',
    'Find documents similar to a given document in a Meilisearch index. Requires a vector embedder on the index.',
)]
#[McpTool(name: 'meili_similar_documents')]
final class SimilarDocumentsTool implements HasSourcesInterface
{
    use HasSourcesTrait;

    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly ResultNormalizer $normalizer,
    ) {
    }

    /**
     * @param string $index      The Meilisearch index UID (e.g. "product").
     * @param string $documentId The primary-key value of the reference document.
     * @param string $embedder   Name of the embedder configured on this index (e.g. "product").
     * @param int    $limit      Maximum number of similar documents to return (1–50).
     */
    public function __invoke(
        string $index,
        string $documentId,
        string $embedder = 'default',
        int $limit = 8,
    ): string {
        $query = (new SimilarDocumentsQuery($documentId, $embedder))
            ->setLimit(max(1, min(50, $limit)));

        try {
            $results = $this->meiliService
                ->getIndexEndpoint($index)
                ->searchSimilarDocuments($query);
        } catch (ApiException $e) {
            // The /similar endpoint returns 400 when no embedder is configured.
            return $this->normalizer->toJson([
                'error'      => $e->getMessage(),
                'index'      => $index,
                'documentId' => $documentId,
            ]);
        }

        $hits = $this->normalizer->normalizeHits($results->getHits());

        foreach ($hits as $hit) {
            $label = $this->normalizer->labelFor($hit);
            $this->addSource(new Source(
                $label,
                sprintf('meili://%s/%s', $index, $hit['id'] ?? '?'),
                $this->normalizer->toJson($hit),
            ));
        }

        return $this->normalizer->toJson([
            'index'      => $index,
            'documentId' => $documentId,
            'hits'       => $hits,
        ]);
    }
}
