<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_scalar;
use function json_encode;
use function preg_replace;
use function preg_split;
use function str_contains;
use function strtolower;
use function substr;

final class OpenApiFieldMetadataResolver
{
    /**
     * @param array<string,mixed> $openApi
     * @param list<string> $fieldNames
     * @return array<string,array<string,mixed>>
     */
    public function resolve(array $openApi, string $indexUid, array $fieldNames): array
    {
        $schemas = $openApi['components']['schemas'] ?? null;
        if (!is_array($schemas) || $schemas === []) {
            return [];
        }

        $bestSchemaName = null;
        $bestSchema = null;
        $bestScore = 0;
        $normalizedIndexNames = $this->normalizedIndexNames($indexUid);

        foreach ($schemas as $schemaName => $schema) {
            if (!is_array($schema)) {
                continue;
            }

            $properties = $schema['properties'] ?? null;
            if (!is_array($properties) || $properties === []) {
                continue;
            }

            $score = $this->scoreSchema((string) $schemaName, $properties, $fieldNames, $normalizedIndexNames);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSchemaName = (string) $schemaName;
                $bestSchema = $schema;
            }
        }

        if ($bestSchemaName === null || !is_array($bestSchema)) {
            return [];
        }

        $properties = $bestSchema['properties'] ?? [];
        $metadata = [];
        foreach ($fieldNames as $fieldName) {
            $property = $properties[$fieldName] ?? null;
            if (!is_array($property)) {
                continue;
            }

            $entry = ['schema' => $bestSchemaName];

            if (isset($property['type']) && is_scalar($property['type'])) {
                $entry['type'] = (string) $property['type'];
            }
            if (isset($property['description']) && is_scalar($property['description'])) {
                $entry['description'] = (string) $property['description'];
            }
            if (isset($property['example'])) {
                $example = $this->normalizeExample($property['example']);
                if ($example !== null) {
                    $entry['example'] = $example;
                }
            }
            if (isset($property['title']) && is_scalar($property['title'])) {
                $entry['title'] = (string) $property['title'];
            }

            if (count($entry) > 1) {
                $metadata[$fieldName] = $entry;
            }
        }

        return $metadata;
    }

    /**
     * @param array<string,mixed> $properties
     * @param list<string> $fieldNames
     * @param list<string> $normalizedIndexNames
     */
    private function scoreSchema(string $schemaName, array $properties, array $fieldNames, array $normalizedIndexNames): int
    {
        $score = 0;
        $normalizedSchemaName = $this->normalizeToken($schemaName);
        foreach ($normalizedIndexNames as $normalizedIndexName) {
            if ($normalizedSchemaName === $normalizedIndexName) {
                $score += 20;
                break;
            }

            if ($normalizedIndexName !== '' && str_contains($normalizedSchemaName, $normalizedIndexName)) {
                $score += 8;
                break;
            }
        }

        $overlap = array_intersect(array_keys($properties), $fieldNames);
        $score += count($overlap) * 2;

        if (array_key_exists('id', $properties)) {
            ++$score;
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function normalizedIndexNames(string $indexUid): array
    {
        $parts = array_values(array_filter(array_map($this->normalizeToken(...), preg_split('/[_-]+/', $indexUid) ?: [])));
        $names = [];
        $names[] = $this->normalizeToken($indexUid);

        if (count($parts) >= 2) {
            $names[] = $parts[count($parts) - 1];
            $names[] = $parts[count($parts) - 2] . $parts[count($parts) - 1];
        }

        return array_values(array_unique(array_filter($names, static fn(string $value): bool => $value !== '')));
    }

    private function normalizeToken(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($value));
    }

    private function normalizeExample(mixed $example): string|null
    {
        if (is_scalar($example)) {
            return (string) $example;
        }

        if (is_array($example)) {
            $encoded = json_encode($example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $encoded === false ? null : substr($encoded, 0, 240);
        }

        return null;
    }
}
