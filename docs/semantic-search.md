# Semantic Search

Meilisearch supports **semantic search** (match by meaning) and **hybrid search** (blend of
keyword and vector scores). This bundle makes it straightforward to configure and use both.

## How it works

1. You configure an *embedder* — an AI model that converts text to a vector.
2. When documents are indexed, Meilisearch calls the embedder to generate a vector for each one.
3. At search time, the query is also embedded and compared to document vectors by distance.
4. Hybrid search blends the keyword score and the vector score using a configurable ratio.

## Configuring an embedder

### 1. Add to `survos_meili.yaml`

```yaml
survos_meili:
    embedders:
        product:                              # name you choose — referenced in #[MeiliIndex]
            source: openAi
            model: text-embedding-3-small     # see cost table below
            apiKey: '%env(OPENAI_API_KEY)%'
            template: 'templates/liquid/product.liquid'
```

### 2. Reference it in `#[MeiliIndex]`

```php
#[MeiliIndex(
    primaryKey: 'sku',
    searchable: ['title', 'description'],
    filterable: ['category', 'brand'],
    embedders: ['product'],          // name must match the YAML key
)]
class Product { ... }
```

### 3. Push settings and generate embeddings

```bash
# Estimate cost first
bin/console meili:estimate Product

# Push updated settings and trigger embedding generation
bin/console meili:settings:update --force --embed product
```

Once complete, semantic search is available immediately.

## Liquid templates

Meilisearch embeds a *text representation* of each document, not raw JSON.
You control that representation with a [Liquid](https://shopify.github.io/liquid/) template.
The template receives `doc` with all document fields.

A good template produces natural-language text that captures what the document *means*,
not just what fields it has.

**Example: `templates/liquid/product.liquid`**

```liquid
{{ doc.title }}
{% if doc.category %} - {{ doc.category }} product{% endif %}
{% if doc.price %} Price: ${{ doc.price }}{% endif %}
{% if doc.brand %} Brand: {{ doc.brand }}{% endif %}
Description: {{ doc.description }}
{% if doc.tags and doc.tags.size > 0 %}
Tags: {% for t in doc.tags %}{{ t }}{% unless forloop.last %}, {% endunless %}{% endfor %}
{% endif %}
{% if doc.stock %} Stock: {{ doc.stock }} units{% endif %}
{% if doc.rating %} Rating: {{ doc.rating }} stars{% endif %}
```

**Important**: guard every optional field with `{% if %}`. Meilisearch's Liquid engine
throws an error if you access a field that does not exist in the document.

### Template path resolution

Set `template` to a path relative to `%kernel.project_dir%`. The bundle reads the file
contents and sends them to Meilisearch. Inline Liquid strings also work:

```yaml
embedders:
    product:
        source: openAi
        model: text-embedding-3-small
        apiKey: '%env(OPENAI_API_KEY)%'
        template: '{{ doc.title }} {{ doc.description }}'  # inline
```

## Estimating cost

Before generating embeddings for a large dataset, run:

```bash
bin/console meili:estimate Product
bin/console meili:estimate Product --embedder=product
```

The command counts rows, estimates token usage from the Liquid template output, and
shows the approximate cost using the pricing table in your config.

### Cost reference (OpenAI, as of early 2025)

| Model | Cost per 1M tokens |
|---|---|
| `text-embedding-3-small` | ~$0.02 |
| `text-embedding-3-large` | ~$0.13 |
| `text-embedding-ada-002` | ~$0.10 |

**Example**: 10,000 products × ~100 tokens per template = ~1M tokens ≈ $0.02 with `3-small`.
A full product catalog is usually just a few cents.

Override pricing in config:

```yaml
survos_meili:
    pricing:
        embedders:
            text-embedding-3-small: 0.02
            text-embedding-3-large: 0.13
```

## Multiple embedders

You can have several embedders — for instance a cheap one for autocomplete and a better one
for full semantic search:

```yaml
survos_meili:
    embedders:
        product:
            source: openAi
            model: text-embedding-3-large
            apiKey: '%env(OPENAI_API_KEY)%'
            template: 'templates/liquid/product.liquid'
        product_fast:
            source: openAi
            model: text-embedding-3-small
            apiKey: '%env(OPENAI_API_KEY)%'
            template: '{{ doc.title }}'
```

```php
#[MeiliIndex(embedders: ['product', 'product_fast'])]
class Product { ... }
```

## Hybrid search

Hybrid search blends keyword and vector scores. The `semanticRatio` controls the blend:

- `0.0` = pure keyword (same as no embedder)
- `1.0` = pure vector (ignores exact word matches)
- `0.5` = balanced (usually the best starting point)

### In the MCP tool

```bash
bin/console meili:mcp:test search_index meili_product \
  "cool gifts for a tech executive" \
  --embedder=product \
  --semantic-ratio=0.6
```

### In PHP

```php
$results = $meiliService->getIndexEndpoint('meili_product')->search(
    'cool gifts for a tech executive',
    [
        'hybrid' => [
            'embedder'      => 'product',
            'semanticRatio' => 0.6,
        ],
        'limit' => 10,
    ]
);
```

## Removing an embedder

Meilisearch's `updateSettings()` **merges** embedders — it cannot delete them.
The bundle handles this correctly: to remove an embedder, set it to `null` in the
settings payload (a PATCH with a null tombstone), which is what `meili:settings:update`
does when it detects a previously-configured embedder is no longer in your YAML.

## Supported providers

| `source` | Description |
|---|---|
| `openAi` | OpenAI Embeddings API (recommended for most projects) |
| `huggingFace` | Hugging Face Inference API |
| `ollama` | Local models via Ollama (no API cost) |
| `userProvided` | You supply pre-computed vectors in the document payload |

See the [Meilisearch embedder documentation](https://www.meilisearch.com/docs/learn/ai_powered_search/getting_started_with_ai_search)
for the full provider reference.
