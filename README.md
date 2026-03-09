# survos/meili-bundle

Symfony bundle for [Meilisearch](https://www.meilisearch.com/) — attribute-driven index configuration,
automatic Doctrine sync, semantic/hybrid search, InstantSearch UI, and MCP/AI agent integration.

Requires PHP 8.4 and Symfony 7.4+.

```bash
composer require survos/meili-bundle
```

## Documentation

| | |
|---|---|
| **[Overview](docs/index.md)** | What the bundle does, quick-start |
| **[Installation](docs/installation.md)** | Docker, env vars, first index |
| **[Configuration](docs/configuration.md)** | Full `survos_meili.yaml` reference |
| **[Indexing attributes](docs/indexing.md)** | `#[MeiliIndex]`, `Fields`, `#[Facet]`, `#[Embedder]` |
| **[Commands](docs/commands.md)** | All console commands with examples |
| **[Semantic search](docs/semantic-search.md)** | Embedders, Liquid templates, cost estimation |
| **[Doctrine sync](docs/sync.md)** | Auto-sync via postFlush, Messenger batching |
| **[MCP / AI agents](docs/mcp.md)** | Expose your data to Claude, ChatGPT, Cursor, etc. |
| [Multilingual](docs/multilingual-index-naming.md) | Multi-language index naming |

See **[docs/mcp.md](docs/mcp.md)** for the full MCP/AI agent setup guide.
