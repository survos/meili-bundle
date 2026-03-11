<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_sum;
use function array_unique;
use function arsort;
use function count;
use function error_get_last;
use function file_get_contents;
use function hash;
use function is_array;
use function is_numeric;
use function is_scalar;
use function json_decode;
use function json_encode;
use function implode;
use function in_array;
use function rawurlencode;
use function rtrim;
use function sort;
use function sprintf;
use function str_starts_with;
use function stream_context_create;
use function trim;

final class CollectionMetadataService
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly OpenApiFieldMetadataResolver $openApiFieldMetadataResolver,
        private readonly ?CacheInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function warmup(string $indexUid, ?string $schemaUrl = null): void
    {
        $this->describeCollection($indexUid, $schemaUrl);
    }

    public function describeCollection(string $indexUid, ?string $schemaUrl = null, int $facetValueLimit = 8): string
    {
        $schemaUrl = $this->normalizeSchemaUrl($schemaUrl);
        $cacheKey = 'meili_collection_meta_' . hash('xxh128', $indexUid . '|' . ($schemaUrl ?? ''));

        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($indexUid, $schemaUrl, $facetValueLimit): string {
                $item->expiresAfter(3600);

                return $this->buildDescription($indexUid, $schemaUrl, $facetValueLimit);
            });
        }

        return $this->buildDescription($indexUid, $schemaUrl, $facetValueLimit);
    }

    private function buildDescription(string $indexUid, ?string $schemaUrl, int $facetValueLimit): string
    {
        $index = $this->meiliService->getIndexEndpoint($indexUid);
        $stats = $index->stats();
        $settings = $index->getSettings();
        $globalStats = $this->safeGlobalStats();
        $fieldsResponse = $this->safeRequestJson($this->buildMeiliUrl('/indexes/' . rawurlencode($indexUid) . '/fields'));
        $openApiDocument = $schemaUrl !== null ? $this->getSchemaDocument($schemaUrl) : null;

        $fieldDistribution = $stats['fieldDistribution'] ?? [];
        if (!is_array($fieldDistribution)) {
            $fieldDistribution = [];
        }
        arsort($fieldDistribution);

        $searchable = $this->stringList($settings['searchableAttributes'] ?? []);
        $filterable = $this->stringList($settings['filterableAttributes'] ?? []);
        $sortable = $this->stringList($settings['sortableAttributes'] ?? []);
        $displayed = $this->stringList($settings['displayedAttributes'] ?? []);
        $overviewFacet = $this->buildOverviewFacet(
            $indexUid,
            (int) ($stats['numberOfDocuments'] ?? 0),
            $filterable,
            [
                'primaryItemType',
                'primary_item_type',
                'object_type',
                'type',
                'category',
                'categories',
                'classification',
                'kind',
                'department',
                'collection',
                'genre',
                'material',
            ],
            'organization',
        );
        $locationFacet = $this->buildOverviewFacet(
            $indexUid,
            (int) ($stats['numberOfDocuments'] ?? 0),
            $filterable,
            [
                'physicalLocation',
                'physical_location',
                'location',
                'currentLocation',
                'current_location',
                'gallery',
                'storageLocation',
                'storage_location',
                'room',
                'building',
            ],
            'location',
        );

        $fieldNames = array_values(array_unique(array_merge(
            array_keys($fieldDistribution),
            $searchable,
            $filterable,
            $sortable,
            $displayed,
            $this->extractFieldNames($fieldsResponse),
        )));
        sort($fieldNames);

        $schemaMetadata = is_array($openApiDocument)
            ? $this->openApiFieldMetadataResolver->resolve($openApiDocument, $indexUid, $fieldNames)
            : [];

        $fields = [];
        foreach ($fieldNames as $fieldName) {
            $entry = ['name' => $fieldName];
            $roles = $this->rolesForField($fieldName, $searchable, $filterable, $sortable, $displayed);
            if ($roles !== []) {
                $entry['roles'] = $roles;
            }

            if (isset($fieldDistribution[$fieldName]) && is_numeric($fieldDistribution[$fieldName])) {
                $entry['distribution'] = (float) $fieldDistribution[$fieldName];
            }

            $fieldMeta = $this->fieldMetadataFromFieldsEndpoint($fieldsResponse, $fieldName);
            if ($fieldMeta !== []) {
                $entry = array_merge($entry, $fieldMeta);
            }

            if (isset($schemaMetadata[$fieldName])) {
                $entry['schema'] = $schemaMetadata[$fieldName];
            }

            $fields[] = $entry;
        }

        $describedFields = array_values(array_filter($fields, static fn(array $field): bool => isset($field['schema']['description'])));
        $topFields = array_map(
            static fn(string $fieldName): array => ['name' => $fieldName, 'distribution' => (float) $fieldDistribution[$fieldName]],
            array_slice(array_keys($fieldDistribution), 0, $facetValueLimit)
        );

        $summaryBits = [
            sprintf('The index contains %d documents.', (int) ($stats['numberOfDocuments'] ?? 0)),
        ];

        if ($topFields !== []) {
            $summaryBits[] = sprintf(
                'Frequently populated fields include %s.',
                implode(', ', array_map(static fn(array $field): string => $field['name'], $topFields))
            );
        }
        if ($filterable !== []) {
            $summaryBits[] = sprintf('You can filter by %s.', implode(', ', array_slice($filterable, 0, 10)));
        }
        if ($describedFields !== []) {
            $summaryBits[] = sprintf('Schema descriptions are available for %d fields.', count($describedFields));
        }

        return (string) json_encode([
            'index' => $indexUid,
            'summary' => implode(' ', $summaryBits),
            'documents' => (int) ($stats['numberOfDocuments'] ?? 0),
            'primaryKey' => $settings['primaryKey'] ?? 'id',
            'searchableAttributes' => $searchable,
            'filterableAttributes' => $filterable,
            'sortableAttributes' => $sortable,
            'displayedAttributes' => $displayed,
            'topFields' => $topFields,
            'overviewFacet' => $overviewFacet,
            'locationFacet' => $locationFacet,
            'describedFieldCount' => count($describedFields),
            'fields' => $fields,
            'indexStats' => [
                'isIndexing' => (bool) ($stats['isIndexing'] ?? false),
                'fieldDistributionCount' => count($fieldDistribution),
            ],
            'serverStats' => $globalStats,
            'schemaUrl' => $schemaUrl,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function buildMeiliUrl(string $path): string
    {
        $config = $this->meiliService->getConfig();
        $host = rtrim((string) ($config['host'] ?? 'http://localhost:7700'), '/');

        return $host . $path;
    }

    /**
     * @param list<string> $filterable
     * @return array<string,mixed>|null
     */
    private function buildOverviewFacet(string $indexUid, int $documents, array $filterable, array $preferredFields, string $kind): ?array
    {
        $facetField = $this->pickOverviewFacetField($filterable, $preferredFields);
        if ($facetField === null) {
            return null;
        }

        try {
            $results = $this->meiliService->getIndexEndpoint($indexUid)->search('', [
                'limit' => 0,
                'facets' => [$facetField],
            ]);
        } catch (\Throwable $exception) {
            $this->logger?->warning('Unable to build overview facet distribution.', [
                'index' => $indexUid,
                'field' => $facetField,
                'exception' => $exception,
            ]);

            return null;
        }

        $distribution = $results->getFacetDistribution()[$facetField] ?? null;
        if (!is_array($distribution) || $distribution === []) {
            return null;
        }

        arsort($distribution);
        $total = $documents > 0 ? $documents : (int) array_sum($distribution);
        $topValues = [];
        foreach (array_slice($distribution, 0, 3, true) as $value => $count) {
            $count = (int) $count;
            $topValues[] = [
                'value' => (string) $value,
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        if ($topValues === []) {
            return null;
        }

        return [
            'kind' => $kind,
            'field' => $facetField,
            'label' => $this->overviewFacetLabel($facetField),
            'values' => $topValues,
        ];
    }

    /**
     * @param list<string> $filterable
     */
    private function pickOverviewFacetField(array $filterable, array $preferred): ?string
    {
        foreach ($preferred as $candidate) {
            if (in_array($candidate, $filterable, true)) {
                return $candidate;
            }
        }

        foreach ($filterable as $field) {
            foreach (['type', 'category', 'class', 'kind', 'department', 'genre'] as $needle) {
                if (str_contains($field, $needle)) {
                    return $field;
                }
            }
        }

        return $filterable[0] ?? null;
    }

    private function overviewFacetLabel(string $fieldName): string
    {
        return match ($fieldName) {
            'primaryItemType', 'primary_item_type' => 'primary item types',
            'object_type', 'type', 'kind' => 'types',
            'category', 'categories' => 'categories',
            'classification' => 'classifications',
            'department' => 'departments',
            'genre' => 'genres',
            'physicalLocation', 'physical_location', 'currentLocation', 'current_location', 'storageLocation', 'storage_location', 'location' => 'locations',
            default => $fieldName,
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getSchemaDocument(string $schemaUrl): ?array
    {
        $cacheKey = 'meili_schema_url_' . hash('xxh128', $schemaUrl);
        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($schemaUrl): ?array {
                $item->expiresAfter(86400);

                return $this->safeRequestJson($schemaUrl, []);
            });
        }

        return $this->safeRequestJson($schemaUrl, []);
    }

    /**
     * @return array<string,mixed>
     */
    private function safeGlobalStats(): array
    {
        try {
            $stats = $this->meiliService->getMeiliClient()->stats();

            return is_array($stats) ? $stats : [];
        } catch (\Throwable $exception) {
            $this->logger?->warning('Unable to fetch Meilisearch global stats.', ['exception' => $exception]);

            return [];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function safeRequestJson(string $url, array $headers = ['Content-Type: application/json']): ?array
    {
        $config = $this->meiliService->getConfig();
        $hasAuthorizationHeader = false;
        foreach ($headers as $header) {
            if (is_scalar($header) && trim((string) $header) !== '' && str_starts_with((string) $header, 'Authorization:')) {
                $hasAuthorizationHeader = true;
                break;
            }
        }

        if (isset($config['apiKey']) && $config['apiKey'] !== '' && !$hasAuthorizationHeader) {
            $headers[] = 'Authorization: Bearer ' . $config['apiKey'];
        }

        try {
            $response = file_get_contents($url, false, stream_context_create(['http' => [
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 10,
            ]]));
        } catch (\Throwable $exception) {
            $this->logger?->warning('Unable to fetch JSON document.', ['url' => $url, 'exception' => $exception]);

            return null;
        }

        if ($response === false) {
            $error = error_get_last();
            $this->logger?->warning('Unable to fetch JSON document.', ['url' => $url, 'error' => $error]);

            return null;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed>|null $fieldsResponse
     * @return list<string>
     */
    private function extractFieldNames(?array $fieldsResponse): array
    {
        if (!is_array($fieldsResponse)) {
            return [];
        }

        $fieldNames = [];
        foreach ($fieldsResponse as $key => $value) {
            if (is_array($value) && isset($value['name']) && is_scalar($value['name'])) {
                $fieldNames[] = (string) $value['name'];
                continue;
            }

            if (!is_numeric($key)) {
                $fieldNames[] = (string) $key;
            }
        }

        return array_values(array_unique($fieldNames));
    }

    /**
     * @param array<string,mixed>|null $fieldsResponse
     * @return array<string,mixed>
     */
    private function fieldMetadataFromFieldsEndpoint(?array $fieldsResponse, string $fieldName): array
    {
        if (!is_array($fieldsResponse)) {
            return [];
        }

        $raw = $fieldsResponse[$fieldName] ?? null;
        if (!is_array($raw)) {
            foreach ($fieldsResponse as $entry) {
                if (is_array($entry) && ($entry['name'] ?? null) === $fieldName) {
                    $raw = $entry;
                    break;
                }
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $metadata = [];
        foreach (['type', 'kind', 'distinct', 'example'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $metadata[$key] = (string) $raw[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param list<string> $searchable
     * @param list<string> $filterable
     * @param list<string> $sortable
     * @param list<string> $displayed
     * @return list<string>
     */
    private function rolesForField(string $fieldName, array $searchable, array $filterable, array $sortable, array $displayed): array
    {
        $roles = [];
        if (array_intersect([$fieldName], $searchable) !== []) {
            $roles[] = 'searchable';
        }
        if (array_intersect([$fieldName], $filterable) !== []) {
            $roles[] = 'filterable';
        }
        if (array_intersect([$fieldName], $sortable) !== []) {
            $roles[] = 'sortable';
        }
        if (array_intersect([$fieldName], $displayed) !== []) {
            $roles[] = 'displayed';
        }

        return $roles;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $item): string {
            return is_scalar($item) ? trim((string) $item) : '';
        }, $value), static fn(string $item): bool => $item !== ''));
    }

    private function normalizeSchemaUrl(?string $schemaUrl): ?string
    {
        if ($schemaUrl === null) {
            return null;
        }

        $schemaUrl = trim($schemaUrl);

        return $schemaUrl === '' ? null : $schemaUrl;
    }
}
