<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Survos\MeiliBundle\Service\OpenApiFieldMetadataResolver;

#[CoversClass(OpenApiFieldMetadataResolver::class)]
final class OpenApiFieldMetadataResolverTest extends TestCase
{
    public function testItSelectsTheSchemaThatBestMatchesTheIndexAndFields(): void
    {
        $resolver = new OpenApiFieldMetadataResolver();

        $metadata = $resolver->resolve([
            'components' => [
                'schemas' => [
                    'Product' => [
                        'properties' => [
                            'sku' => ['description' => 'Product SKU'],
                            'title' => ['description' => 'Display title'],
                        ],
                    ],
                    'CollectionObject' => [
                        'properties' => [
                            'id' => ['type' => 'integer', 'description' => 'Object identifier'],
                            'title' => ['type' => 'string', 'description' => 'Public title'],
                            'object_type' => ['type' => 'string', 'description' => 'Collection object category'],
                        ],
                    ],
                ],
            ],
        ], 'meili_collection_object', ['id', 'title', 'object_type']);

        self::assertSame('CollectionObject', $metadata['title']['schema']);
        self::assertSame('Public title', $metadata['title']['description']);
        self::assertSame('Collection object category', $metadata['object_type']['description']);
    }

    public function testItKeepsScalarAndArrayExamples(): void
    {
        $resolver = new OpenApiFieldMetadataResolver();

        $metadata = $resolver->resolve([
            'components' => [
                'schemas' => [
                    'Object' => [
                        'properties' => [
                            'title' => ['description' => 'Title', 'example' => 'Blue Vase'],
                            'tags' => ['description' => 'Keywords', 'example' => ['ceramic', 'blue']],
                        ],
                    ],
                ],
            ],
        ], 'object', ['title', 'tags']);

        self::assertSame('Blue Vase', $metadata['title']['example']);
        self::assertSame('["ceramic","blue"]', $metadata['tags']['example']);
    }
}
