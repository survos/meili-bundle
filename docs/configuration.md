# Configuration Reference

Full `survos_meili.yaml` reference. Every key has a sensible default; start minimal and add as needed.

## Minimal configuration

```yaml
survos_meili:
    host:      '%env(default::MEILI_SERVER)%'
    apiKey:    '%env(default::MEILI_API_KEY)%'
    searchKey: '%env(default::MEILI_SEARCH_KEY)%'
```

## Full reference

```yaml
survos_meili:

    # Meilisearch connection
    host:      '%env(default::MEILI_SERVER)%'    # default: http://localhost:7700
    apiKey:    '%env(default::MEILI_API_KEY)%'   # admin key (server-side only)
    searchKey: '%env(default::MEILI_SEARCH_KEY)%' # public search-only key (safe for frontend)

    # Optional prefix applied to all index UIDs (e.g. "meili_" → "meili_product")
    # Useful when sharing one Meilisearch instance across multiple apps/environments.
    meiliPrefix: '%env(default::MEILI_PREFIX)%'  # default: empty

    # Directories scanned for entities with #[MeiliIndex]
    entity_dirs:
        - '%kernel.project_dir%/src/Entity'
        - '%kernel.project_dir%/src/Index'

    # -------------------------------------------------------------------------
    # Embedders for semantic / hybrid search
    # -------------------------------------------------------------------------
    embedders:
        # Key is the embedder name referenced in #[MeiliIndex(embedders: ['product'])]
        product:
            source: openAi                      # openAi | huggingFace | ollama | userProvided
            model: text-embedding-3-small        # model sent to the provider
            apiKey: '%env(OPENAI_API_KEY)%'      # provider API key
            template: 'templates/liquid/product.liquid'  # Liquid template path OR inline string
            documentTemplateMaxBytes: 4096       # truncate template output to this many bytes
            # for: product                       # optional: restrict to a specific index

    # -------------------------------------------------------------------------
    # Embedding cost table (used by meili:estimate)
    # -------------------------------------------------------------------------
    pricing:
        embedders:
            text-embedding-3-small: 0.02   # $ per 1M tokens
            text-embedding-3-large: 0.13
            text-embedding-ada-002: 0.10

    # -------------------------------------------------------------------------
    # Meilisearch index-level settings applied globally
    # -------------------------------------------------------------------------
    meili_settings:
        typoTolerance:
            enabled: true
            oneTypo: 5        # min word length before 1 typo is allowed
            twoTypos: 9       # min word length before 2 typos are allowed
            disableOnNumbers: false
            disableOnWords: []
            disableOnAttributes: []
        faceting:
            maxValuesPerFacet: 1000
            sortFacetValuesBy:
                '*': count    # 'count' | 'alpha'
        pagination:
            maxTotalHits: 1000
        facetSearch: true
        prefixSearch: indexingTime   # 'indexingTime' | 'disabled'

    # -------------------------------------------------------------------------
    # File proxy (serve local files through Symfony)
    # -------------------------------------------------------------------------
    file_proxy:
        enabled: true
        allow_hidden: false
        cache_control: 'private, max-age=60'
        roots: []

    # -------------------------------------------------------------------------
    # Chat workspaces (Meilisearch native chat / AI completions)
    # -------------------------------------------------------------------------
    chat:
        workspaces:
            default:
                source: openAi           # LLM provider
                apiKey: '%env(OPENAI_API_KEY)%'
                model: gpt-4o-mini
                indexes: []              # index UIDs this workspace may access
```

## Index prefix

The `meiliPrefix` is prepended to every index UID the bundle creates or queries.

```yaml
survos_meili:
    meiliPrefix: 'myapp_'
```

With this set, an entity `Product` maps to the index `myapp_product`.
Use `%env(default::MEILI_PREFIX)%` to control it per environment via `.env.local`.

## Embedder sources

| `source` value | Provider |
|---|---|
| `openAi` | OpenAI Embeddings API |
| `huggingFace` | Hugging Face Inference API |
| `ollama` | Ollama (local models) |
| `userProvided` | You supply vectors manually |

## Related

- [Semantic search](semantic-search.md) — Liquid templates, cost estimation, updating embeddings
- [Indexing attributes](indexing.md) — per-entity configuration via `#[MeiliIndex]`
