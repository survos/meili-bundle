<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Tool\GetDocumentTool;
use Survos\MeiliBundle\Tool\DescribeCollectionTool;
use Survos\MeiliBundle\Tool\SearchFacetsTool;
use Survos\MeiliBundle\Tool\SearchIndexTool;
use Survos\MeiliBundle\Tool\SimilarDocumentsTool;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function json_decode;
use function json_encode;
use function sprintf;

/**
 * Smoke-test the Meilisearch AI tool layer from the CLI.
 *
 * Examples:
 *   bin/console meili:mcp:test search_index product "cool gifts for techies"
 *   bin/console meili:mcp:test get_document product B07HC56D6Q
 *   bin/console meili:mcp:test similar_documents product B07HC56D6Q
 *   bin/console meili:mcp:test search_facets product "laptop" "category,brand"
 */
#[AsCommand('meili:mcp:test', 'Invoke a Meilisearch AI tool and pretty-print the result')]
final class MeiliMcpTestCommand
{
    public function __construct(
        private readonly SearchIndexTool $searchIndexTool,
        private readonly GetDocumentTool $getDocumentTool,
        private readonly SimilarDocumentsTool $similarDocumentsTool,
        private readonly SearchFacetsTool $searchFacetsTool,
        private readonly DescribeCollectionTool $describeCollectionTool,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Tool name: search_index | get_document | similar_documents | search_facets | describe_collection')]
        string $tool,
        #[Argument('Index UID (e.g. "meili_product")')]
        string $index,
        #[Argument('Query string, document ID, or facet attributes depending on the tool')]
        string $query,
        #[Option('Additional argument (embedder for similar_documents, facet attrs for search_facets)')]
        ?string $extra = null,
        #[Option('Max results to return')]
        int $limit = 8,
        #[Option('Meilisearch filter expression')]
        ?string $filter = null,
        #[Option('Embedder name for hybrid search_index (enables semantic blending)')]
        ?string $embedder = null,
        #[Option('Semantic ratio for hybrid search_index (0.0=keyword, 1.0=vector)')]
        float $semanticRatio = 0.5,
    ): int {
        $json = match ($tool) {
            'search_index'       => ($this->searchIndexTool)($index, $query, $limit, $filter, $embedder, $semanticRatio),
            'get_document'       => ($this->getDocumentTool)($index, $query),
            // $query = documentId, $extra = embedder name (defaults to 'default')
            'similar_documents'  => ($this->similarDocumentsTool)($index, $query, $extra ?? 'default', $limit),
            // $extra = comma-separated facet attributes
            'search_facets'      => ($this->searchFacetsTool)($index, $query, $extra ?? 'id', $filter),
            'describe_collection' => ($this->describeCollectionTool)($index, $extra, $limit),
            default              => null,
        };

        if ($json === null) {
            $io->error(sprintf(
                'Unknown tool "%s". Available: search_index, get_document, similar_documents, search_facets, describe_collection',
                $tool
            ));
            return Command::FAILURE;
        }

        $decoded = json_decode($json, true);
        $pretty  = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $io->title(sprintf('Tool: %s  |  Index: %s  |  Query: %s', $tool, $index, $query));
        $io->writeln($pretty);

        return Command::SUCCESS;
    }
}
