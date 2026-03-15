<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Survos\MeiliBundle\Model\MeiliSuggestion;

/**
 * Converts a generic JsonlBundle-style profile array into Meilisearch settings.
 *
 * This service does NOT depend on JsonlProfile directly; instead it expects the
 * normalized `fields` shape produced by ImportBundle's import:convert command.
 */
final class MeiliFieldHeuristic
{
    /**
     * @param array<string, array<string, mixed>> $fields
     */
    public function suggestFromFields(array $fields): MeiliSuggestion
    {
        $facetFields = [];
        $searchableFields = [];
        $sortableFields = [];

        foreach ($fields as $name => $stats) {
            $types       = $stats['types'] ?? [];
            $distinct    = $stats['distinct'] ?? null;
            $total       = $stats['total'] ?? null;
            $storageHint = $stats['storageHint'] ?? null;
            $booleanLike = $stats['booleanLike'] ?? false;
            $urlLike     = $stats['urlLike'] ?? false;
            $imageLike   = $stats['imageLike'] ?? false;
            $nlpLike     = $stats['naturalLanguageLike'] ?? false;
            $arrayCount  = $stats['arrayStats']['arrays'] ?? 0;

            if (!\is_string($name)) {
                continue;
            }

            // URLs, images, IIIF: display only, never search/facet.
            if ($urlLike || $imageLike || $this->looksLikeUrl($name) || $this->looksLikeIiif($name)) {
                continue;
            }

            // Rights/licence text: display only (too long and free-form for useful search)
            if ($this->looksLikeRightsText($name)) {
                continue;
            }

            // Geographic sub-fields: filterable, not full-text searchable
            if ($this->looksLikeGeo($name)) {
                $facetFields[] = $name;
                if (\in_array('float', $types, true) || $storageHint === 'float') {
                    $sortableFields[] = $name;
                }
                continue;
            }

            // Heuristic: id-like fields → sortable only
            if ($this->looksLikeId($name)) {
                $sortableFields[] = $name;
                continue;
            }

            // Heuristic: numeric / float => sortable, facetable if low cardinality
            if (\in_array('int', $types, true) || \in_array('float', $types, true)
                || $storageHint === 'float' || $storageHint === 'int') {
                $sortableFields[] = $name;
                if ($distinct !== null && $total !== null && $distinct <= 500) {
                    $facetFields[] = $name;
                }
                continue;
            }

            // Heuristic: boolean-like => facet only
            if ($booleanLike === true || $storageHint === 'bool') {
                $facetFields[] = $name;
                continue;
            }

            // Heuristic: array fields (multi-value) => facet + searchable
            // These are the most valuable facets: genre, subject, type_of_resource etc.
            if ($arrayCount > 0) {
                $facetFields[] = $name;
                // Also searchable if values look like natural language (subjects, genres)
                if (!$this->looksLikeCodeOrId($name)) {
                    $searchableFields[] = $name;
                }
                continue;
            }

            // Heuristic: short string enums => facet
            // Relaxed: allow distinct=1 (small collections still benefit from facets as data grows)
            if (\in_array('string', $types, true) && $distinct !== null && $total !== null) {
                $ratio = $total > 0 ? $distinct / $total : 1.0;
                if ($distinct <= 500 && $ratio <= 0.6) {
                    $facetFields[] = $name;
                    continue;
                }
            }

            // Long natural-language text => searchable only
            if ($nlpLike || \in_array('string', $types, true)) {
                $searchableFields[] = $name;
            }
        }

        return new MeiliSuggestion(
            facetFields: $facetFields,
            searchableFields: $searchableFields,
            sortableFields: $sortableFields,
        );
    }

    private function looksLikeId(string $field): bool
    {
        $lower = \strtolower($field);

        return \str_ends_with($lower, 'id')
            || $lower === 'id'
            || $lower === 'pk'
            || \str_ends_with($lower, '_id');
    }

    /**
     * Fields that look like internal codes/identifiers and shouldn't be searchable
     * even if they're array fields (e.g. media_id, source_id).
     */
    private function looksLikeRightsText(string $field): bool
    {
        $lower = strtolower($field);
        return in_array($lower, ['rights', 'license', 'licence', 'rights_statement',
                                  'reuse_allowed', 'access_rights', 'copyright'], true);
    }

    private function looksLikeGeo(string $field): bool
    {
        $lower = strtolower($field);
        return in_array($lower, ['country', 'state', 'county', 'city', 'city_section',
                                  'region', 'province', 'locality', 'place'], true);
    }

    private function looksLikeUrl(string $field): bool
    {
        $lower = strtolower($field);
        return str_ends_with($lower, '_url')
            || str_ends_with($lower, '_uri')
            || str_ends_with($lower, '_link')
            || $lower === 'url'
            || $lower === 'uri';
    }

    private function looksLikeIiif(string $field): bool
    {
        return str_starts_with(strtolower($field), 'iiif');
    }

    private function looksLikeCodeOrId(string $field): bool
    {
        $lower = \strtolower($field);
        return $this->looksLikeId($field)
            || \str_contains($lower, 'code')
            || \str_contains($lower, 'key')
            || \str_contains($lower, 'hash')
            || \str_contains($lower, 'slug');
    }
}
