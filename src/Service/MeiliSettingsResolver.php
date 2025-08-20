<?php

// packages/meili-bundle/src/Service/MeiliSettingsResolver.php
final class MeiliSettingsResolver
{
    /** @param array<string,array<string>> ...$sources */
    public function merge(array ...$sources): array
    {
        $filterable=[]; $sortable=[]; $searchable=[]; $displayed=[];
        foreach ($sources as $s) {
            $filterable = array_merge($filterable, $s['filterableAttributes'] ?? []);
            $sortable   = array_merge($sortable,   $s['sortableAttributes']   ?? []);
            $searchable = array_merge($searchable, $s['searchableAttributes'] ?? []);
            $displayed  = array_merge($displayed,  $s['displayedAttributes']  ?? []);
        }
        $uniq = fn(array $a) => array_values(array_unique($a));

        // Always include 'core', and if you collapse locales, include 'lang'
        $filterable = $uniq(array_merge(['core'], $filterable));

        // Good defaults for displayed
        if (!$displayed) $displayed = ['id','label','description'];

        return [
            'filterableAttributes' => $uniq($filterable),
            'sortableAttributes'   => $uniq($sortable),
            'searchableAttributes' => $uniq($searchable),
            'displayedAttributes'  => $uniq($displayed),
        ];
    }
}
