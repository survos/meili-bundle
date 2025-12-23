<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Registry;

use Survos\CoreBundle\Service\SurvosUtils;

final class MeiliRegistry
{
    /**
     * @param array<string,string> $indexEntities map: rawIndexName => class
     * @param array<class-string, array<string,array<string,mixed>>> $indexSettings map: class => [rawIndexName => settings]
     */
    public function __construct(
        private readonly array $indexEntities,
        private readonly array $indexSettings,
        private readonly string $prefix = '',
    ) {
    }

    /** @return string[] raw (unprefixed) index names */
    public function names(): array
    {
        $names = array_keys($this->indexEntities);
        sort($names);
        return $names;
    }

    public function has(string $rawIndexName): bool
    {
        return isset($this->indexEntities[$rawIndexName]);
    }

    public function classFor(string $rawIndexName): ?string
    {
        return $this->indexEntities[$rawIndexName] ?? null;
    }

    /** Return settings block for an index (schema+facets+ui+primaryKey, etc.) */
    public function settingsFor(string $rawIndexName): ?array
    {
        $class = $this->classFor($rawIndexName);
        if (!$class) {
            return null;
        }
        return $this->indexSettings[$class][$rawIndexName] ?? null;
    }

    /** The Meili UID that will be used on the server (prefix applied if configured). */
    public function uidFor(string $rawIndexName): string
    {
        if ($this->prefix && !str_starts_with($rawIndexName, $this->prefix)) {
            return $this->prefix . $rawIndexName;
        }
        return $rawIndexName;
    }

    /** @return array<string, array{class:string, primaryKey:string, schema:array, facets:array, ui:array}> */
    public function summary(?string $filter = null): array
    {
        $out = [];
        foreach ($this->names() as $name) {
            if ($filter && !str_contains($name, $filter)) {
                continue;
            }
            $class = $this->classFor($name) ?? '';
            $cfg = $this->settingsFor($name) ?? [];

            $out[$name] = [
                'class' => $class,
                'primaryKey' => (string)($cfg['primaryKey'] ?? ''),
                'schema' => (array)($cfg['schema'] ?? []),
                'facets' => (array)($cfg['facets'] ?? []),
                'ui' => (array)($cfg['ui'] ?? []),
            ];
        }
        return $out;
    }
}
