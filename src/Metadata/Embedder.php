<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

use Attribute;

/**
 * DTO for Meilisearch Embedders configuration.
 * Example:
 *  new Embedder(
 *     name: 'open_ai_small',
 *     source: 'openAi',
 *     model: 'text-embedding-3-small',
 *     apiKeyParameter: 'OPENAI_API_KEY',
 *     documentTemplate: 'Instrument {{ doc.name }} ...' // blade syntax
 *  )
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Embedder
{
    public function __construct(
        public string $name,
        public string $source,
        public string $model,
        public ?string $apiKeyParameter = null, // read from env/params in the updater
        public ?string $documentTemplate = null // blade template string
    ) {}
}
