<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Model;

/**
 * Simple DTO for suggested Meilisearch settings derived from a profile.
 */
final class MeiliSuggestion
{
    /**
     * @param string[] $facetFields
     * @param string[] $searchableFields
     * @param string[] $sortableFields
     */
    public function __construct(
        public array $facetFields = [],
        public array $searchableFields = [],
        public array $sortableFields = [],
    ) {
    }

    /**
     * JSON-serializable representation suitable for Meilisearch settings API.
     *
     * {
     *   "filterableAttributes": [...],
     *   "searchableAttributes": [...],
     *   "sortableAttributes": [...]
     * }
     */
    public function toSettingsArray(): array
    {
        return [
            'filterableAttributes' => \array_values(\array_unique($this->facetFields)),
            'searchableAttributes' => \array_values(\array_unique($this->searchableFields)),
            'sortableAttributes'   => \array_values(\array_unique($this->sortableFields)),
        ];
    }
}
