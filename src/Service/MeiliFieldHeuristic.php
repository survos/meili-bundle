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
            $types = $stats['types'] ?? [];
            $distinct = $stats['distinct'] ?? null;
            $total = $stats['total'] ?? null;
            $storageHint = $stats['storageHint'] ?? null;
            $booleanLike = $stats['booleanLike'] ?? false;

            if (!\is_string($name)) {
                continue;
            }

            // Heuristic: id-like fields
            if ($this->looksLikeId($name)) {
                $sortableFields[] = $name;
                continue;
            }

            // Heuristic: numeric or date-like => sortable, sometimes facetable
            if (\in_array('int', $types, true) || \in_array('float', $types, true)) {
                $sortableFields[] = $name;
                if ($distinct !== null && $total !== null && $distinct <= 200) {
                    $facetFields[] = $name;
                }
                continue;
            }

            // Heuristic: boolean-like => facet
            if ($booleanLike === true || $storageHint === 'bool') {
                $facetFields[] = $name;
                continue;
            }

            // Heuristic: short string enums => facet
            if (\in_array('string', $types, true) && $distinct !== null && $total !== null) {
                $ratio = $total > 0 ? $distinct / $total : 1.0;
                if ($distinct > 1 && $distinct <= 200 && $ratio <= 0.6) {
                    $facetFields[] = $name;
                    continue;
                }
            }

            // Default: text-like fields become searchable
            if (\in_array('string', $types, true)) {
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
}
