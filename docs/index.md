# survos/meili-bundle Documentation

A Symfony bundle for integrating [Meilisearch](https://www.meilisearch.com/) into your application.
Configure indexes with PHP attributes on your Doctrine entities, populate them with a single command,
keep them in sync automatically, and expose your data to AI agents via MCP.

## What it does

- **Attribute-driven configuration** — put `#[MeiliIndex]` on any Doctrine entity and the bundle derives
  the Meilisearch index settings (searchable, filterable, sortable fields) from it.
- **One-command indexing** — `meili:settings:update` pushes settings; `meili:populate` streams all rows.
- **Automatic sync** — a Doctrine `postFlush` listener dispatches Messenger messages whenever entities
  are created, updated, or deleted, keeping the search index current in real time.
- **Semantic / hybrid search** — configure OpenAI (or other) embedders in YAML; the bundle manages
  vector generation and provides `meili:estimate` to forecast cost before you spend money.
- **InstantSearch UI** — a Symfony UX LiveComponent (`InstantSearchComponent`) renders a full
  faceted search interface with zero JavaScript boilerplate.
- **EasyAdmin integration** — an optional dashboard and menu factory for browsing/managing indexes
  from within an EasyAdmin back-office.
- **MCP / AI agent tools** — four `#[AsTool]` / `#[McpTool]` services expose search, document lookup,
  similarity search, and facet counts to any MCP-compatible AI client (Claude Desktop, ChatGPT, Cursor…).

## Table of contents

| Document | What it covers |
|---|---|
| [Installation](installation.md) | Requirements, composer install, env vars, first index |
| [Configuration](configuration.md) | Full `survos_meili` YAML reference |
| [Indexing](indexing.md) | `#[MeiliIndex]`, `Fields`, `#[Facet]`, `#[Embedder]` attributes |
| [Commands](commands.md) | All console commands with examples |
| [Semantic search](semantic-search.md) | Embedders, Liquid templates, cost estimation |
| [Doctrine sync](sync.md) | Auto-sync via postFlush, Messenger batching |
| [MCP / AI agents](mcp.md) | Expose your indexes to Claude, ChatGPT, Cursor, etc. |
| [Multilingual](multilingual-index-naming.md) | Multi-language index naming strategy |

## Quick-start (30 seconds)

```bash
composer require survos/meili-bundle
```

Add to `.env.local`:

```dotenv
MEILI_SERVER=http://localhost:7700
MEILI_API_KEY=your-admin-key
MEILI_SEARCH_KEY=your-search-key
```

Annotate your entity:

```php
use Survos\MeiliBundle\Metadata\MeiliIndex;

#[MeiliIndex(
    searchable: ['title', 'description'],
    filterable: ['category', 'brand'],
    sortable: ['price', 'rating'],
)]
#[ORM\Entity]
class Product { ... }
```

Create the index and populate it:

```bash
bin/console meili:settings:update --force
bin/console meili:populate product --all
```

That's it. Your data is now searchable.
