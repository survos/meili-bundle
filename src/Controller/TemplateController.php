<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Controller;

use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TemplateController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/js/')]
        private string $jsTemplateDir,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private readonly MeiliService $meiliService,
    ) {
    }

    #[Route('/template/{templateName}', name: 'meili_template')]
    public function jsTemplate(string $templateName): Response
    {
        // Normalize locale suffix: "movies_en" → "movies"
        $templateName = preg_replace('/_..$/', '', $templateName);

        // While developing, always regenerate from profile + Meili;
        // later we can re-enable the on-disk JS-Twig override.
        /*
        */
        $path = $this->jsTemplateDir . $templateName . '.html.twig';
        if (file_exists($path)) {
            return new Response(file_get_contents($path) ?: '');
        }

        $profilePath = $this->projectDir . '/data/' . $templateName . '.profile.json';
        if (!is_file($profilePath)) {
            return new Response(
                sprintf('Missing profile file %s', $profilePath),
                Response::HTTP_NOT_FOUND
            );
        }

        $profile = json_decode(file_get_contents($profilePath) ?: '[]', true) ?? [];

        [$config, $settings] = $this->buildConfigFromProfile($templateName, $profile);

        $twig = $this->generateJsTwigFromConfig($config, $settings);

        return new Response($twig);
    }

    /**
     * Heuristic helper: treat *_id / *Id etc. as ID-like. We don't want
     * these as facets or badges by default.
     */
    private function isIdLike(string $fieldName): bool
    {
        $lower = strtolower($fieldName);

        // direct "id" or snake_case "*_id"
        if (preg_match('/(^|_)id$/', $lower)) {
            return true;
        }

        // camelCase or PascalCase: bookId, workId, goodreadsBookId, etc.
        if (str_ends_with($lower, 'id') && !str_ends_with($lower, 'grid')) {
            return true;
        }

        return false;
    }

    /**
     * Map a profile field name (snake_case) to the Meili field name
     * (camelCase), mimicking CodeEntityCommand (u($field)->camel()).
     */
    private function profileFieldToMeiliField(string $profileField): string
    {
        $propName = preg_replace('/[^a-zA-Z0-9_]/', '_', $profileField);
        $propName = strtolower($propName);

        $parts = explode('_', $propName);
        $first = array_shift($parts);
        $camel = $first;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $camel .= ucfirst($part);
        }

        return $camel;
    }

    /**
     * Turn a field name (camelCase or snake_case) into a human label,
     * e.g. "ratingsCount" → "Ratings Count", "small_image_url" → "Small Image Url".
     */
    private function humanizeField(string $field): string
    {
        // Insert spaces before capitals: ratingsCount → ratings Count
        $s = preg_replace('/(?<!^)[A-Z]/', ' $0', $field) ?? $field;
        // Underscores to spaces
        $s = str_replace('_', ' ', $s);
        $s = trim($s);

        return ucwords(strtolower($s));
    }

    /**
     * Build heuristic config using Meilisearch settings + Jsonl profile data.
     */
    private function buildConfigFromProfile(string $indexName, array $profile): array
    {
        $fields        = $profile['fields'] ?? [];
        $pkFromProfile = $profile['pk'] ?? null;

        $client = $this->meiliService->getMeiliClient();
        $index  = $client->getIndex($this->meiliService->getPrefixedIndexName($indexName));

        // Use Meili's primary key API (e.g. "code" for Marvel), fallback to profile, then "id".
        $primaryKey = $index->getPrimaryKey() ?? $pkFromProfile ?? 'id';

        $settings      = $index->getSettings();
        $searchable    = $settings['searchableAttributes'] ?? [];
        $rawFilterable = $settings['filterableAttributes'] ?? [];

        // Build a mapping between profile field names and Meili field names
        // profile: image_url → Meili: imageUrl, original_title → originalTitle, etc.
        $profileToMeili = [];
        $meiliToProfile = [];
        foreach ($fields as $profileField => $_meta) {
            $meiliField                     = $this->profileFieldToMeiliField($profileField);
            $profileToMeili[$profileField]  = $meiliField;
            $meiliToProfile[$meiliField]    = $profileField;
        }

        // Strip ID-like fields from our UI facet/view config (still allowed in Meili).
        $filterable = array_values(array_filter(
            $rawFilterable,
            fn (string $f) => !$this->isIdLike($f)
        ));

        // Helper: pick a good *profile* field for title based on its profile stats,
        // then map it to the Meili field name via $profileToMeili.
        $pickProfileString = static function (array $profileFieldNames, array $profileFields, array $preferred): ?string {
            $fallback = null;
            foreach ($profileFieldNames as $pf) {
                if (!isset($profileFields[$pf])) {
                    continue;
                }
                $meta = $profileFields[$pf];
                if (!in_array('string', $meta['types'] ?? [], true)) {
                    continue;
                }
                if (in_array($pf, $preferred, true)) {
                    return $pf;
                }
                $fallback ??= $pf;
            }

            return $fallback;
        };

        $allProfileFieldNames = array_keys($fields);

        // 1) Title field: use profile first (so "title"/"original_title" win even if
        //    Meili's searchableAttributes are wrong, like the Goodreads "authors" case).
        $profileTitleField = $pickProfileString(
            $allProfileFieldNames,
            $fields,
            ['title', 'original_title', 'name', 'label', 'heading']
        );
        $titleField = $profileTitleField
            ? ($profileToMeili[$profileTitleField] ?? $profileTitleField)
            : null;

        // Fallback: if that completely failed, use Meili searchable attributes.
        if (!$titleField) {
            foreach ($searchable as $s) {
                $profileField = $meiliToProfile[$s] ?? null;
                if (!$profileField) {
                    continue;
                }
                $meta = $fields[$profileField] ?? null;
                if (!$meta || !in_array('string', $meta['types'] ?? [], true)) {
                    continue;
                }
                $titleField = $s;
                break;
            }
        }

        $titleField ??= $primaryKey;

        // 2) Description: another stringy profile field, preferably long-ish,
        // mapped back to Meili field name.
        $descriptionField = null;

// 1) Explicitly prefer "description", "overview", "summary", "abstract", "notes"
        $descriptionCandidates = ['description', 'overview', 'summary', 'abstract', 'notes'];
        foreach ($descriptionCandidates as $cand) {
            if (isset($fields[$cand]) && $cand !== $profileTitleField) {
                $descriptionField = $profileToMeili[$cand] ?? $cand;
                break;
            }
        }

// 2) Fallback: longest-ish string field (what we had before)
        if (!$descriptionField) {
            foreach ($allProfileFieldNames as $pf) {
                if ($pf === $profileTitleField) {
                    continue;
                }
                $meta = $fields[$pf] ?? null;
                if (!$meta || !in_array('string', $meta['types'] ?? [], true)) {
                    continue;
                }
                $maxLen = $meta['stringLengths']['max'] ?? 0;
                if ($maxLen > 40) {
                    $descriptionField = $profileToMeili[$pf] ?? $pf;
                    break;
                }
            }
        }

        // 3) Scalar filterable: year, budget, votes, etc. (skip *_id)
        $scalarFields = [];
        foreach ($filterable as $meiliField) {
            if ($this->isIdLike($meiliField)) {
                continue;
            }
            $profileField = $meiliToProfile[$meiliField] ?? null;
            if (!$profileField) {
                continue;
            }
            $meta = $fields[$profileField] ?? null;
            if (!$meta) {
                continue;
            }

            $hint = $meta['storageHint'] ?? null;
            $bool = $meta['booleanLike'] ?? false;

            if (in_array($hint, ['int', 'float', 'number'], true) || $bool) {
                $scalarFields[] = $meiliField;
            }
            if (count($scalarFields) >= 3) {
                break;
            }
        }

        // 4) Tag-like / array-ish fields: genres, tags, authors, powers, etc. (skip *_id)
        $tagFields    = [];
        $tagNameHints = [
            'genres', 'genre',
            'tags', 'tag',
            'categories', 'category',
            'keywords', 'labels',
            'authors', 'powers', 'teams', 'species', 'partners',
        ];

        foreach ($filterable as $meiliField) {
            if ($this->isIdLike($meiliField)) {
                continue;
            }
            $profileField = $meiliToProfile[$meiliField] ?? null;
            if (!$profileField) {
                continue;
            }

            $meta  = $fields[$profileField] ?? null;
            if (!$meta) {
                continue;
            }
            $types = $meta['types'] ?? [];
            $facet = $meta['facetCandidate'] ?? false;
            $lname = strtolower($meiliField);

            $isArrayish    = in_array('array', $types, true);
            $isStringFacet = in_array('string', $types, true) && $facet;
            $isNameHint    = in_array($lname, $tagNameHints, true);

            // Also drop "degenerate" facets: single value used everywhere.
            $distribution = $meta['distribution']['values'] ?? null;
            $total        = $meta['total'] ?? null;
            $degenerate   = false;
            if (is_array($distribution) && $total) {
                if (count($distribution) === 1 && reset($distribution) === $total) {
                    $degenerate = true;
                }
            }

            if ($degenerate) {
                continue;
            }

            if ($isArrayish || $isStringFacet || $isNameHint) {
                $tagFields[] = $meiliField;
            }
            if (count($tagFields) >= 2) {
                break;
            }
        }

        // 5) Image-ish field from profile (snake_case, like image_url/small_image_url)
        //    mapped to Meili field name (imageUrl/smallImageUrl).
        $imageField = null;
        foreach ($fields as $profileField => $meta) {
            $key = strtolower($profileField);
            if (
                (str_contains($key, 'image') ||
                 str_contains($key, 'thumb') ||
                 str_contains($key, 'poster') ||
                 str_contains($key, 'cover')) &&
                (($meta['storageHint'] ?? '') === 'string')
            ) {
                $imageField = $profileToMeili[$profileField] ?? $profileField;
                break;
            }
        }

        // Human labels for Meili field names (camel/snake → Title Case)
        $labels = [];
        foreach ($fields as $profileField => $_meta) {
            $meiliField        = $profileToMeili[$profileField] ?? $profileField;
            $labels[$meiliField] = $this->humanizeField($meiliField);
        }

        // We'll rely on CSS line clamp instead of character slicing,
        // but keep these knobs for arrays / max list.
        $maxLen  = 100; // not actually used for clamping now, but kept as a hint
        $maxList = 3;   // how many items from array-like fields

        return [
            [
                'primaryKey'       => $primaryKey,
                'titleField'       => $titleField,
                'descriptionField' => $descriptionField,
                'imageField'       => $imageField,
                'scalarFields'     => $scalarFields,
                'tagFields'        => $tagFields,
                'filterableFields' => $filterable, // already stripped of *_id
                'labels'           => $labels,
                'maxLen'           => $maxLen,
                'maxList'          => $maxList,
            ],
            $settings,
        ];
    }

    /**
     * Generate the JS-Twig card (Twig.js-compatible).
     * - Small thumbnail left, text wrapping to the right
     * - Arrays (facets) BELOW the image+text row
     * - CSS line clamp on description
     * - Skip 0/blank scalar values
     * - Show ranking score as 0–100 badge when in [0,1]
     */
    private function generateJsTwigFromConfig(array $config, array $settings): string
    {
        $configJson   = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $settingsJson = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<TWIG
{# Generated automatically from JSONL profile + Meilisearch settings.
   Safe to edit; re-generating will overwrite.

   Meilisearch settings:
$settingsJson
#}

{% set _config = $configJson %}

{% set pk        = attribute(hit, _config.primaryKey|default('id')) ?? (hit.id ?? null) %}
{% set titleKey  = _config.titleField|default('title') %}
{% set descKey   = _config.descriptionField|default(null) %}
{% set imageKey  = _config.imageField|default(null) %}
{% set maxLen    = _config.maxLen|default(100) %}
{% set maxList   = _config.maxList|default(3) %}
{% set labels    = _config.labels|default({}) %}

{# Title with highlight #}
{% set highlightedTitle = (hit._highlightResult is defined
    and attribute(hit._highlightResult, titleKey) is defined
    and attribute(attribute(hit._highlightResult, titleKey), 'value') is defined)
    ? attribute(attribute(hit._highlightResult, titleKey), 'value')
    : null
%}
{% set title = highlightedTitle ?? (attribute(hit, titleKey)|default(pk)) %}

{# Description (may be null if there's only 1 searchable field) #}
{% set description = null %}
{% if descKey %}
    {% set highlightedDesc = (hit._highlightResult is defined
        and attribute(hit._highlightResult, descKey) is defined
        and attribute(attribute(hit._highlightResult, descKey), 'value') is defined)
        ? attribute(attribute(hit._highlightResult, descKey), 'value')
        : null
    %}
    {% set rawDesc = attribute(hit, descKey)|default(null) %}
    {% set description = highlightedDesc ?? rawDesc %}
{% endif %}

{# Image: config-driven first, then common fallbacks like posterUrl/poster_url/image/thumbnail,
   then nested images.thumbnail / images.background (Marvel-style) #}
{% set imageUrl = imageKey ? (attribute(hit, imageKey)|default(null)) : null %}
{% if not imageUrl %}
    {% set imageUrl = attribute(hit, 'posterUrl')|default(
        attribute(hit, 'poster_url')|default(
            attribute(hit, 'image')|default(
                attribute(hit, 'thumbnail')|default(null)
            )
        )
    ) %}
{% endif %}
{% if not imageUrl and attribute(hit, 'images') is defined and hit.images is not null %}
    {% set images = hit.images %}
    {% set imageUrl = attribute(images, 'thumbnail')|default(
        attribute(images, 'background')|default(null)
    ) %}
{% endif %}

<div class="card h-100 shadow-sm border-0">
    <div class="card-body p-3">
        <div class="d-flex gap-3 mb-2">
            {% if imageUrl %}
                <div class="meili-hit-thumb" style="flex:0 0 96px;">
                    <img
                        src="{{ imageUrl }}"
                        alt="{{ title|striptags }}"
                        class="img-fluid rounded"
                        loading="lazy"
                        decoding="async"
                        style="max-height:120px;object-fit:cover;"
                    >
                </div>
            {% endif %}

            <div class="flex-grow-1">
                <div class="d-flex justify-content-between gap-2 mb-1">
                    <h5 class="card-title mb-0 text-wrap" style="word-break:break-word;text-align:left;">
                        {{ title|raw }}
                    </h5>

                    {# First scalar field as badge (year, etc.), skipping 0/empty #}
                    {% set scalars = _config.scalarFields|default([]) %}
                    {% if scalars|length > 0 %}
                        {% set sf = scalars[0] %}
                        {% set sfVal = attribute(hit, sf)|default(null) %}
                        {% if sfVal is not null and sfVal != 0 and sfVal != '' %}
                            <span class="badge bg-primary-subtle text-primary-emphasis">
                                {{ sfVal }}
                            </span>
                        {% endif %}
                    {% endif %}
                </div>

                {# Description, CSS-clamped to ~3 lines (no char slicing) #}
                {% if description %}
                    <p
                        class="text-body-secondary mb-0"
                        style="
                            display:-webkit-box;
                            -webkit-line-clamp:3;
                            -webkit-box-orient:vertical;
                            overflow:hidden;
                            text-overflow:ellipsis;
                        "
                    >
                        {{ description|raw }}
                    </p>
                {% endif %}

                {# Fallback when there's *no* description: show first few filterable fields as a tiny summary #}
                {% if description is null %}
                    {% set filterableFields = _config.filterableFields|default([]) %}
                    {% if filterableFields|length > 0 %}
                        <dl class="row small text-body-secondary mb-0 mt-1">
                            {% for f in filterableFields|slice(0,3) %}
                                {% set v = attribute(hit, f)|default(null) %}
                                {% if v is not null and v != 0 and v != '' %}
                                    {% set label = labels[f]|default(f) %}
                                    <dt class="col-4 text-truncate">{{ label }}</dt>
                                    <dd class="col-8 text-truncate">
                                        {% if v is iterable %}
                                            {% for item in v|slice(0, maxList) %}
                                                {{ loop.first ? '' : ', ' }}{{ item }}
                                            {% endfor %}
                                        {% else %}
                                            {{ v }}
                                        {% endif %}
                                    </dd>
                                {% endif %}
                            {% endfor %}
                        </dl>
                    {% endif %}
                {% endif %}
            </div>
        </div>

        {# Remaining scalar fields as badges (budget, votes, etc.), skipping 0/empty #}
        {% if scalars|length > 1 %}
            <div class="d-flex flex-wrap gap-2 small mb-2">
                {% for sf in scalars|slice(1) %}
                    {% set v = attribute(hit, sf)|default(null) %}
                    {% if v is not null and v != 0 and v != '' %}
                        {% set label = labels[sf]|default(sf) %}
                        <span class="badge bg-light text-body-secondary">
                            {{ label }}:
                            {% if v is iterable %}
                                {% for item in v|slice(0, maxList) %}
                                    {{ loop.first ? '' : ', ' }}{{ item }}
                                {% endfor %}
                            {% else %}
                                {{ v }}
                            {% endif %}
                        </span>
                    {% endif %}
                {% endfor %}
            </div>
        {% endif %}

        {# Tag / array fields like genres, authors, powers, etc. BELOW the image/text row #}
        {% set tagFields = _config.tagFields|default([]) %}
        {% if tagFields|length > 0 %}
            <div class="d-flex flex-wrap gap-2 small mt-1">
                {% for tf in tagFields %}
                    {% set v = attribute(hit, tf)|default(null) %}
                    {% if v is not null %}
                        {% set label = labels[tf]|default(tf) %}
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <span class="fw-semibold text-body-secondary">{{ label }}:</span>
                            {% if v is iterable %}
                                {% for item in v|slice(0, maxList) %}
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ item }}</span>
                                {% endfor %}
                                {% if v|length > maxList %}
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">
                                        +{{ v|length - maxList }}
                                    </span>
                                {% endif %}
                            {% else %}
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ v }}</span>
                            {% endif %}
                        </div>
                    {% endif %}
                {% endfor %}
            </div>
        {% endif %}
    </div>

    <div class="card-footer bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex flex-wrap align-items-center gap-2 small text-body-secondary">
            {% if pk is not null %}
                <span>{{ _config.primaryKey }}: {{ pk }}</span>
            {% endif %}

            {# Ranking score as 0–100 badge when in [0,1] #}
            {% if hit._rankingScore is defined and hit._rankingScore is not null %}
                {% set rawScore = hit._rankingScore %}
                {% if rawScore >= 0 and rawScore <= 1 %}
                    {% set scorePct = (rawScore * 100)|round(1, 'common') %}
                    <span class="badge bg-info-subtle text-info-emphasis">
                        {{ scorePct }}%
                    </span>
                {% else %}
                    <span class="badge bg-info-subtle text-info-emphasis">
                        {{ rawScore }}
                    </span>
                {% endif %}
            {% endif %}
        </div>

        {# JSON modal trigger (do not change) #}
        <button
            {{ stimulus_action(globals._sc_modal, 'modal') }}
            data-hit-id="{{ pk }}"
            class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1"
        >
            {{ ux_icon('json')|raw }}
            <span>Details</span>
        </button>
    </div>
</div>
TWIG;
    }
}
