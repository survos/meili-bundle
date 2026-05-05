# Console Commands

All bundle commands follow the `meili:` namespace. Run `bin/console list meili` to see them all.

---

## `meili:settings:update`

Push index settings (searchable, filterable, sortable fields, embedders) to Meilisearch.
Reads `#[MeiliIndex]` attributes and calls the Meilisearch settings API.

```bash
bin/console meili:settings:update              # dry-run: show what would change
bin/console meili:settings:update --force      # apply changes
bin/console meili:settings:update --force product          # single index only
bin/console meili:settings:update --force --embed          # also trigger vector re-generation
bin/console meili:settings:update --force --reset product  # reset index settings to default first
```

**Use `--force` to actually apply changes.** Without it the command only reports what would change.

**Use `--embed` after adding or changing an embedder.** This tells Meilisearch to re-embed
all documents. It can take a while and incurs API cost — use `meili:estimate` first.

---

## `meili:populate`

Stream Doctrine entity rows into a Meilisearch index.

```bash
bin/console meili:populate product            # populate the 'product' index
bin/console meili:populate product --all      # include soft-deleted / disabled rows
bin/console meili:populate --all              # populate every registered index
bin/console meili:populate product --limit=500 --offset=1000  # paginate
```

The command iterates through the entity table in batches, normalizes each row
using the Symfony Serializer (respecting `#[Groups]` from `#[MeiliIndex]`),
and uploads via `addDocuments()`.

---

## `meili:estimate`

Estimate token usage and embedding cost **before** you run `meili:settings:update --embed`.

```bash
bin/console meili:estimate Product
bin/console meili:estimate Product --embedder=product
```

Sample output:

```
Entity:  App\Entity\Product
Rows:    12 450
Tokens:  ~1 245 000 (est.)
Cost:    ~$0.025 (text-embedding-3-small @ $0.02/1M tokens)
```

---

## `meili:schema:update`

Alias / variant of `meili:settings:update` focused on schema-level changes
(creating the index if it does not exist, setting the primary key).

```bash
bin/console meili:schema:update
bin/console meili:schema:update --force
```

---

## `meili:schema:validate`

Check that the Meilisearch index settings match what the `#[MeiliIndex]` attributes declare.
Exits non-zero if there are discrepancies — useful in CI.

```bash
bin/console meili:schema:validate
bin/console meili:schema:validate product
```

---

## `meili:registry:report`

Display a table of all registered indexes, their entity classes, and current Meilisearch stats.

```bash
bin/console meili:registry:report
```

---

## `meili:registry:sync`

Sync the local `IndexInfo` database table with the live Meilisearch stats
(document count, field distribution, last update time).

```bash
bin/console meili:registry:sync
```

---

## `meili:suggest:settings`

Analyse an index's field distribution and suggest which fields are good candidates
for `filterable`, `sortable`, and `searchable`. Useful when you have an existing index
but are not sure how to configure it.

```bash
bin/console meili:suggest:settings product
```

---

## `meili:export`

Export all documents from an index to NDJSON or JSON.

```bash
bin/console meili:export product --output=var/product.jsonl
bin/console meili:export product --format=json
```

---

## `meili:iterate:indexes`

Iterate over every document in every registered index. Primarily a developer tool
for inspecting or processing documents programmatically.

```bash
bin/console meili:iterate:indexes
bin/console meili:iterate:indexes product
```

---

## `meili:flush:file`

Flush a pending NDJSON spool file to Meilisearch (used by the file-based spool writer).

```bash
bin/console meili:flush:file
```

---

## `meili:mcp:test`

Smoke-test the AI tool layer from the CLI without starting an AI agent.
Only available when `symfony/ai-agent` is installed.

```bash
bin/console meili:mcp:test search_index meili_product "wireless headphones"
bin/console meili:mcp:test search_index meili_product "cool gifts for techies" --embedder=product
bin/console meili:mcp:test get_document meili_product ABC-123
bin/console meili:mcp:test similar_documents meili_product ABC-123 --extra=product
bin/console meili:mcp:test search_facets meili_product "laptop" --extra="category,brand"
```

Arguments:

| Argument | Description |
|---|---|
| `tool` | `search_index`, `get_document`, `similar_documents`, `search_facets` |
| `index` | Meilisearch index UID (e.g. `meili_product`) |
| `query` | Query string, document ID, or facet attributes depending on tool |

Options:

| Option | Description |
|---|---|
| `--extra` | Extra argument: embedder name for `similar_documents`, facet attributes for `search_facets` |
| `--limit` | Max results (default: 8) |
| `--filter` | Meilisearch filter expression |
| `--embedder` | Embedder name for hybrid `search_index` |
| `--semantic-ratio` | Blend 0.0 (pure keyword) – 1.0 (pure vector), default 0.5 |

---

## Typical workflow

```bash
# 1. Create index and push settings
bin/console meili:settings:update --force

# 2. Populate with existing data
bin/console meili:populate product --all

# 3. Check what's registered
bin/console meili:registry:report

# 4. After changing #[MeiliIndex] attributes
bin/console meili:settings:update --force product

# 5. After adding an embedder
bin/console meili:estimate Product
bin/console meili:settings:update --force --embed product

# 6. Validate settings match code (CI)
bin/console meili:schema:validate
```
