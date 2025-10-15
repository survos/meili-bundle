<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Util;

/**
 * Minimal runtime-normalizer for Meili embedders.
 * Constructor arg is injected from bundle config; any %env()% will be
 * resolved by Symfony when this service is created (runtime), so callers
 * never touch the container or ParameterBag.
 */
final class ResolvedEmbeddersProvider
{
    /** @param array<string,mixed> $rawEmbedders */
    public function __construct(private readonly array $rawEmbedders) {}

    /**
     * Return the exact structure Meili expects for $index->updateEmbedders($embedders).
     *
     * @return array<string,array{source:string,model:string,documentTemplate:?string,apiKey:?string}>
     */
    public function forMeili(): array
    {
        $out = [];
        foreach ($this->rawEmbedders as $name => $cfg) {
            if (!\is_array($cfg)) {
                continue;
            }
            // allow 'template' alias
            $tpl = $cfg['documentTemplate'] ?? $cfg['template'] ?? null;
            $source = (string)($cfg['source'] ?? '');
            $model  = (string)($cfg['model']  ?? '');
            if ($source === '' || $model === '') {
                continue; // silently skip invalid
            }

            $item = [
                'source'           => $source,
                'model'            => $model,
                'documentTemplate' => $tpl !== null && $tpl !== '' ? (string)$tpl : null,
                'apiKey'           => isset($cfg['apiKey']) && $cfg['apiKey'] !== '' ? (string)$cfg['apiKey'] : null,
            ];

            /** @var array{source:string,model:string,documentTemplate:?string,apiKey:?string} $item */
            $out[(string)$name] = $item;
        }
        return $out;
    }
}
