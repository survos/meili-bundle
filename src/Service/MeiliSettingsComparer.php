<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Meilisearch\Client;

final class MeiliSettingsComparer
{
    public function __construct(
        private readonly Client $meili,
        /** @var array<string,array<string,mixed>> indexName => compiled settings */
        private readonly array $compiledSettings,
        private readonly ?string $prefix = null
    ) {}

    /** @return array{pending:int,diff:array<string,mixed>} */
    public function check(string $indexName): array
    {
        $uid = $this->prefixed($indexName);

        $pending = $this->pendingTasks($uid);
        $live    = $this->liveSettings($uid);
        $want    = $this->wantSettings($indexName);

        return [
            'pending' => $pending,
            'diff'    => $this->diff($want, $live),
        ];
    }

    private function prefixed(string $name): string
    {
        $p = trim((string)($this->prefix ?? ''), '._-');
        return $p !== '' ? $p . '_' . $name : $name;
    }

    private function pendingTasks(string $uid): int
    {
        $resp = $this->meili->getTasks([
            'indexUids' => [$uid],
            'statuses' => ['enqueued','processing'],
            'limit' => 1000,
        ]);
        return \count($resp['results'] ?? []);
    }

    /** @return array<string,mixed> */
    private function liveSettings(string $uid): array
    {
        try {
            $s = $this->meili->index($uid)->getSettings();
        } catch (\Throwable) {
            // Index may not exist yet; treat as empty
            $s = [];
        }
        return [
            'displayedAttributes'  => $s['displayedAttributes']  ?? ['*'],
            'filterableAttributes' => array_values($s['filterableAttributes'] ?? []),
            'sortableAttributes'   => array_values($s['sortableAttributes'] ?? []),
            'searchableAttributes' => $s['searchableAttributes'] ?? ['*'],
        ];
    }

    /** @return array<string,mixed> */
    private function wantSettings(string $indexName): array
    {
        $cfg = $this->compiledSettings[$indexName] ?? [];

        $display    = $cfg['display']    ?? ['*'];
        $filterable = $cfg['filterable'] ?? [];
        $sortable   = $cfg['sortable']   ?? [];
        $searchable = $cfg['searchable'] ?? [];

        return [
            'displayedAttributes'  => $display ?: ['*'],
            'filterableAttributes' => array_values(array_unique($filterable)),
            'sortableAttributes'   => array_values(array_unique($sortable)),
            'searchableAttributes' => $searchable ?: ['*'],
        ];
    }

    /** @return array<string,array{want:mixed,live:mixed}|null> */
    private function diff(array $want, array $live): array
    {
        $out = [];
        foreach (['displayedAttributes','filterableAttributes','sortableAttributes','searchableAttributes'] as $k) {
            $w = $want[$k] ?? null;
            $l = $live[$k] ?? null;

            // compare with order sensitivity (Meili cares about order for displayed/searchable)
            if ($w !== $l) {
                $out[$k] = ['want' => $w, 'live' => $l];
            }
        }
        return $out;
    }
}
