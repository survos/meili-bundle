<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use function array_filter;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function is_array;
use function is_string;
use function json_encode;

/**
 * Shapes raw Meilisearch hit arrays into compact, LLM-friendly payloads.
 *
 * By default every field is passed through. Once the bundle grows attribute-
 * driven field selection (#[Expose] or similar) the whitelist will be populated
 * automatically; callers can also supply an explicit list.
 */
final class ResultNormalizer
{
    /**
     * Normalize a single raw hit from Meilisearch.
     *
     * @param array<string,mixed> $hit        Raw hit from the SDK.
     * @param list<string>|null   $whitelist  If supplied, only these fields are kept.
     *                                        Pass null (default) to keep everything.
     * @return array<string,mixed>
     */
    public function normalizeHit(array $hit, ?array $whitelist = null): array
    {
        if ($whitelist !== null && $whitelist !== []) {
            $hit = array_intersect_key($hit, array_flip($whitelist));
        }

        // Strip internal Meilisearch metadata fields that add noise for LLMs.
        unset($hit['_formatted'], $hit['_matchesPosition'], $hit['_gradient']);

        return $hit;
    }

    /**
     * Normalize a collection of hits.
     *
     * @param array<int,array<string,mixed>> $hits
     * @param list<string>|null              $whitelist
     * @return array<int,array<string,mixed>>
     */
    public function normalizeHits(array $hits, ?array $whitelist = null): array
    {
        $out = [];
        foreach ($hits as $hit) {
            if (is_array($hit)) {
                $out[] = $this->normalizeHit($hit, $whitelist);
            }
        }
        return $out;
    }

    /**
     * Build a short human-readable label from a hit for use as a Source name.
     *
     * Tries common title-like fields in order; falls back to the primary key value.
     *
     * @param array<string,mixed> $hit
     */
    public function labelFor(array $hit): string
    {
        foreach (['title', 'name', 'label', 'subject', 'heading'] as $field) {
            if (isset($hit[$field]) && is_string($hit[$field]) && $hit[$field] !== '') {
                return $hit[$field];
            }
        }

        // Fall back to first string scalar value we find.
        foreach ($hit as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '(untitled)';
    }

    /**
     * Serialize a normalized hit to a compact JSON string suitable for an LLM tool result.
     *
     * @param array<string,mixed> $hit
     */
    public function toJson(array $hit): string
    {
        return (string) json_encode($hit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
