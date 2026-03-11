<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\MeiliBundle\Service\CollectionMetadataService;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'meili_describe_collection',
    'Describe a Meilisearch collection using index stats, field metadata, and an optional OpenAPI schema URL. Use this for overview or collection-level questions before listing documents.',
)]
#[McpTool(name: 'meili_describe_collection')]
final class DescribeCollectionTool
{
    public function __construct(
        private readonly CollectionMetadataService $collectionMetadataService,
    ) {
    }

    public function __invoke(
        string $index,
        ?string $schemaUrl = null,
        int $facetValueLimit = 8,
    ): string {
        return $this->collectionMetadataService->describeCollection($index, $schemaUrl, $facetValueLimit);
    }
}
