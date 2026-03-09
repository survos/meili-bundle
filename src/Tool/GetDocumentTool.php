<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Meilisearch\Exceptions\ApiException;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\ResultNormalizer;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesInterface;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesTrait;
use Symfony\AI\Agent\Toolbox\Source\Source;

use function sprintf;

/**
 * Symfony AI tool: fetch a single document by its primary-key value.
 *
 * Requires symfony/ai-agent (^0.6).
 * Optionally exposed as an MCP tool when symfony/mcp-bundle is installed.
 */
#[AsTool(
    'meili_get_document',
    'Fetch a single document from a Meilisearch index by its primary-key value.',
)]
#[McpTool(name: 'meili_get_document')]
final class GetDocumentTool implements HasSourcesInterface
{
    use HasSourcesTrait;

    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly ResultNormalizer $normalizer,
    ) {
    }

    /**
     * @param string $index      The Meilisearch index UID (e.g. "product").
     * @param string $documentId The primary-key value of the document to fetch.
     */
    public function __invoke(
        string $index,
        string $documentId,
    ): string {
        try {
            $raw = $this->meiliService
                ->getIndexEndpoint($index)
                ->getDocument($documentId);
        } catch (ApiException $e) {
            if ($e->httpStatus === 404) {
                return $this->normalizer->toJson([
                    'error'      => 'not_found',
                    'index'      => $index,
                    'documentId' => $documentId,
                ]);
            }
            throw $e;
        }

        $hit = $this->normalizer->normalizeHit((array) $raw);
        $label = $this->normalizer->labelFor($hit);

        $this->addSource(new Source(
            $label,
            sprintf('meili://%s/%s', $index, $documentId),
            $this->normalizer->toJson($hit),
        ));

        return $this->normalizer->toJson($hit);
    }
}
