<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Util;

use function file_get_contents;
use function is_file;
use function rtrim;
use function str_starts_with;

/**
 * Minimal runtime-normalizer for Meili embedders.
 * Constructor args are injected from bundle config; any %env()% will be
 * resolved by Symfony when this service is created (runtime), so callers
 * never touch the container or ParameterBag.
 *
 * When the `template` / `documentTemplate` config value looks like a file
 * path (relative or absolute), the file contents are read and used as the
 * Liquid template string.  Relative paths are resolved against $projectDir.
 * This allows YAML config like:
 *
 *   embedders:
 *     product:
 *       template: 'templates/liquid/product.liquid'
 *
 * while sending the rendered Liquid string to Meilisearch's documentTemplate
 * field, not the bare path (which Meilisearch cannot resolve).
 */
final class ResolvedEmbeddersProvider
{
    /**
     * @param array<string,mixed> $rawEmbedders
     * @param string              $projectDir   %kernel.project_dir%
     */
    public function __construct(
        private readonly array $rawEmbedders,
        private readonly string $projectDir = '',
    ) {}

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
            // allow 'template' alias for 'documentTemplate'
            $tpl = $cfg['documentTemplate'] ?? $cfg['template'] ?? null;
            $source = (string)($cfg['source'] ?? '');
            $model  = (string)($cfg['model']  ?? '');
            if ($source === '' || $model === '') {
                continue; // silently skip invalid
            }

            $resolvedTpl = null;
            if ($tpl !== null && $tpl !== '') {
                $resolvedTpl = $this->resolveTemplate((string) $tpl);
            }

            $item = [
                'source'           => $source,
                'model'            => $model,
                'documentTemplate' => $resolvedTpl,
                'apiKey'           => isset($cfg['apiKey']) && $cfg['apiKey'] !== '' ? (string)$cfg['apiKey'] : null,
            ];

            /** @var array{source:string,model:string,documentTemplate:?string,apiKey:?string} $item */
            $out[(string)$name] = $item;
        }
        return $out;
    }

    /**
     * If $value is a readable file path (absolute, or relative to project dir),
     * return its contents.  Otherwise return $value as-is (it's already a
     * Liquid template string).
     */
    private function resolveTemplate(string $value): string
    {
        // Absolute path
        if (str_starts_with($value, '/') && is_file($value)) {
            return (string) file_get_contents($value);
        }

        // Relative path — resolve against project dir
        if ($this->projectDir !== '') {
            $absolute = rtrim($this->projectDir, '/') . '/' . $value;
            if (is_file($absolute)) {
                return (string) file_get_contents($absolute);
            }
        }

        // Not a file path — treat as inline Liquid template string
        return $value;
    }
}
