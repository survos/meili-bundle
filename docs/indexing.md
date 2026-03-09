# Indexing — Attributes Reference

The bundle uses PHP attributes on Doctrine entities (or any plain PHP class) to declare
everything Meilisearch needs: index settings, field selections, facet UI hints, and embedders.

## `#[MeiliIndex]`

The main attribute. Place it on any class that should be indexed.

```php
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MeiliBundle\Metadata\Fields;

#[MeiliIndex(
    primaryKey: 'id',
    searchable: ['title', 'description'],
    filterable: ['category', 'brand', 'price'],
    sortable:   ['price', 'rating'],
)]
#[ORM\Entity]
class Product { ... }
```

### Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `name` | `?string` | class name, lowercased | Override the index UID (without prefix) |
| `primaryKey` | `?string` | `'id'` | Meilisearch primary key field |
| `enabled` | `?bool` | `true` | Set `false` to skip this entity entirely |
| `autoIndex` | `bool` | `true` | Whether the Doctrine listener auto-syncs changes |
| `searchable` | `Fields\|array` | `[]` | Fields included in full-text search |
| `filterable` | `Fields\|array` | `[]` | Fields available for filter expressions and facets |
| `sortable` | `Fields\|array` | `[]` | Fields available for sort expressions |
| `displayed` | `Fields\|array` | `['*']` | Fields returned in search results (`'*'` = all) |
| `persisted` | `Fields\|array` | `[]` | Fields normalized into the document payload sent to Meili |
| `embedders` | `string[]` | `[]` | Embedder names (from `survos_meili.embedders`) to attach |
| `ui` | `array` | `[]` | Frontend presentation hints (see below) |

### Index name

By default the index UID is the lowercase short class name, plus the configured prefix.
`Product` → `product` (or `meili_product` with `meiliPrefix: meili_`).

Override explicitly:

```php
#[MeiliIndex(name: 'catalogue')]
class Product { ... }
```

### The `ui` map

Controls the built-in InstantSearch component rendering:

```php
#[MeiliIndex(
    ui: [
        'columns'        => 3,
        'template'       => '@App/search/product_card.html.twig',
        'cardClass'      => 'meili-card shadow-sm',
        'layout'         => 'bootstrap',
        'showScore'      => false,
        'showJsonButton' => true,
    ]
)]
```

## `Fields` — field selection with Serializer groups

`Fields` lets you select fields either by name or by Symfony Serializer `#[Groups]`.
This is especially useful when your entity already has groups for an API Platform resource.

```php
use Survos\MeiliBundle\Metadata\Fields;

// By explicit field names
#[MeiliIndex(searchable: ['title', 'overview'])]

// By Serializer group
#[MeiliIndex(
    searchable: new Fields(groups: ['product.searchable']),
    filterable: new Fields(fields: ['category', 'brand', 'price']),
    persisted:  new Fields(groups: ['product.read', 'product.details']),
)]
```

When groups are specified, the bundle asks the Symfony Serializer which properties
belong to those groups and uses that list — no duplication with your API config.

### Sharing configuration with API Platform

If you already have `#[ApiResource]` with normalization groups, reuse them:

```php
#[ApiResource(
    normalizationContext: ['groups' => [self::READ, self::DETAILS]],
)]
#[MeiliIndex(
    persisted:  new Fields(groups: [self::READ, self::DETAILS]),
    searchable: new Fields(groups: [self::SEARCHABLE]),
    filterable: new Fields(fields: ['category', 'tags', 'rating', 'price', 'brand']),
    sortable:   ['price', 'rating'],
)]
class Product
{
    const READ       = 'product.read';
    const DETAILS    = 'product.details';
    const SEARCHABLE = 'product.searchable';
}
```

## `#[Facet]`

Place on entity properties to add UI hints for the InstantSearch facet panel.

```php
use Survos\MeiliBundle\Metadata\Facet;
use Survos\MeiliBundle\Metadata\FacetWidget;

#[Facet(label: 'Category', order: 1, showMoreThreshold: 12)]
public ?string $category;

#[Facet(label: 'Price', widget: FacetWidget::RANGE, format: 'price')]
public ?float $price;

#[Facet(label: 'Brand', sortMode: 'alpha', searchable: true)]
public ?string $brand;

#[Facet(label: 'Rating', widget: FacetWidget::RATING, collapsed: true)]
public ?float $rating;
```

### `#[Facet]` parameters

| Parameter | Type | Description |
|---|---|---|
| `label` | `?string` | Human-readable label in the UI |
| `order` | `int` | Sort order in the facet panel (lower = first) |
| `widget` | `FacetWidget\|string` | Widget type: `REFINEMENT_LIST`, `MENU`, `RANGE`, `RATING`, `TOGGLE` |
| `format` | `?string` | Display format hint: `'price'`, `'monthIndex'`, etc. |
| `collapsed` | `?bool` | Start collapsed; user can expand |
| `limit` | `?int` | Initial number of values shown |
| `showMoreLimit` | `?int` | Max shown when expanded |
| `searchable` | `?bool` | Enable in-facet search box |
| `sortMode` | `'count'\|'alpha'\|null` | Sort values by hit count or alphabetically |
| `lookup` | `array` | Map raw values to display labels: `['en' => 'English']` |
| `visible` | `?bool` | Force show/hide; `null` = follow global config |

## `#[Embedder]` attribute (per-class override)

You can declare an embedder directly on an entity class instead of (or in addition to)
the global `survos_meili.embedders` YAML config:

```php
use Survos\MeiliBundle\Metadata\Embedder;

#[Embedder(
    name: 'product',
    source: 'openAi',
    model: 'text-embedding-3-small',
    apiKeyParameter: 'OPENAI_API_KEY',
    documentTemplate: 'templates/liquid/product.liquid',
)]
#[MeiliIndex(embedders: ['product'])]
class Product { ... }
```

In practice, most projects configure embedders once in YAML and reference them by name
in `#[MeiliIndex(embedders: [...])]`.

## Repeatable attribute

`#[MeiliIndex]` is repeatable. Use this to map one entity to multiple indexes
(e.g., a base index and a lightweight autocomplete index):

```php
#[MeiliIndex(
    name: 'product',
    searchable: ['title', 'description', 'brand'],
    filterable: ['category', 'price', 'rating'],
)]
#[MeiliIndex(
    name: 'product_suggest',
    searchable: ['title'],
    displayed: ['title', 'sku'],
)]
class Product { ... }
```

## `autoIndex: false`

Set this when you control indexing manually (e.g. from an import pipeline) and
do not want the Doctrine listener to sync changes automatically:

```php
#[MeiliIndex(autoIndex: false)]
class ImportedRow { ... }
```

You can still populate with `bin/console meili:populate importedrow --all` and
update settings with `meili:settings:update`.

## Primary key

Meilisearch requires every document to have a unique primary key. By default the
bundle uses `id`. Override to match your entity:

```php
#[MeiliIndex(primaryKey: 'imdbId')]
class Movie { ... }

#[MeiliIndex(primaryKey: 'sku')]
class Product { ... }
```

If the entity uses a composite key or a non-standard field, the bundle can compute
it via `MeiliDocumentInterface` — see `src/Metadata/MeiliDocumentInterface.php`.
