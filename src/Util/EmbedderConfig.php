<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Util;

use Symfony\Component\DependencyInjection\ContainerBuilder;

final class EmbedderConfig
{
    /**
     * Extract, resolve, and normalize the 'embedders' section from the bundle config
     * into the exact structure required by Meili's $index->updateEmbedders($embedders).
     *
     * Expected input (root config):
     * [
     *   'embedders' => [
     *     'open_ai_small' => [
     *       'source' => 'openAi',
     *       'model'  => 'text-embedding-3-small',
     *       'documentTemplate' => 'product {{ doc.title }} ...',
     *       'apiKey' => '%env(OPENAI_API_KEY)%',
     *     ],
     *     ...
     *   ],
     *   ... other bundle config ...
     * ]
     *
     * Also accepts 'template' as an alias for 'documentTemplate'.
     *
     * @param array<string,mixed> $bundleConfig  Full bundle config root (e.g. from AbstractBundle::loadExtension)
     * @return array<string,array{source:string,model:string,documentTemplate:?string,apiKey:?string}>
     */
    public static function resolveEmbedders(array $bundleConfig, ContainerBuilder $container): array
    {
        $bag = $container->getParameterBag();
        $raw = $bundleConfig['embedders'] ?? [];

        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $name => $cfg) {
            if (!\is_array($cfg)) {
                // Skip invalid entries silently; keep this minimal/forgiving.
                continue;
            }

            // Support 'template' as alias for 'documentTemplate'
            $template = $cfg['documentTemplate'] ?? $cfg['template'] ?? null;

            $item = [
                'source'            => isset($cfg['source']) ? (string)$cfg['source'] : '',
                'model'             => isset($cfg['model']) ? (string)$cfg['model'] : '',
                'documentTemplate'  => $template !== null ? (string)$template : null,
                'apiKey'            => $cfg['apiKey'] ?? null,
            ];

            // Resolve env/params on every scalar value
            foreach ($item as $k => $v) {
                if (\is_string($v)) {
                    $item[$k] = (string)$bag->resolveValue($v);
                }
            }

            // Keep only non-empty required fields
            if ($item['source'] === '' || $item['model'] === '') {
                continue;
            }

            // Normalize empty strings to null for optional fields
            if ($item['documentTemplate'] === '') {
                $item['documentTemplate'] = null;
            }
            if ($item['apiKey'] === '') {
                $item['apiKey'] = null;
            }

            /** @var array{source:string,model:string,documentTemplate:?string,apiKey:?string} $item */
            $out[(string)$name] = $item;
        }

        return $out;
    }
}
