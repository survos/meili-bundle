# Plan: Expose Meilisearch as MCP Tools in `survos/meili-bundle` using Symfony AI

## Goal

Add a **generic MCP integration layer** to `survos/meili-bundle` so AI agents can use Meilisearch as a tool-driven retrieval backend through **Symfony AI**.

This is separate from Meilisearch’s native **Chat / conversational search** feature.

The bundle should support **two AI integration modes**:

1. **Native Meilisearch Chat mode**
    - uses Meilisearch chat workspaces
    - Meilisearch owns retrieval + prompt orchestration + answer generation
    - best for simple conversational search UIs :contentReference[oaicite:0]{index=0}

2. **MCP Tool mode**
    - an external agent does the reasoning
    - the agent calls Meilisearch through MCP tools exposed by the bundle
    - best for planning-style tasks such as tours, lesson prep, exhibit design, and curation workflows :contentReference[oaicite:1]{index=1}

---

## Why this belongs in `survos/meili-bundle`

Meilisearch already documents an MCP server for talking to Meilisearch via natural-language AI assistants, and its docs explicitly position MCP as an alternative to conversational search for broader tool-driven use cases. :contentReference[oaicite:2]{index=2}

However, in `survos/meili-bundle`, we still want a **bundle-level abstraction** because the bundle can add application-specific behavior on top of raw Meilisearch, such as:

- tenant-aware index resolution
- locale-aware index selection
- field whitelisting / shaping
- museum-safe result formatting
- alias resolution across multiple indexes
- reusable Symfony service contracts

So even though Meilisearch already has an MCP story, there is still strong value in exposing **generic MCP tools backed by the bundle**.

---

## Symfony AI fit

Symfony AI provides:

- an **Agent** framework for tool-driven workflows
- a **Chat** abstraction
- **Mate**, which provides an MCP server for AI assistants
- an MCP bundle / extension model for exposing tools from a PHP application :contentReference[oaicite:3]{index=3}

Important note:

- Symfony AI **Mate** is documented as a **development tool**, not a production deployment mechanism. :contentReference[oaicite:4]{index=4}

So for this bundle, the implementation should target:

- **Symfony AI tool definitions**
- a clean MCP-facing service surface
- optional Mate integration for local development
- no assumption that Mate itself is the production runtime

---

## Architectural Decision

`survos/meili-bundle` should expose Meilisearch capabilities as **generic agent tools**.

The agent remains responsible for:

- interpreting the user’s goal
- deciding what to search
- calling one or more tools
- synthesizing the final answer

Example user request:

> I'm preparing a tour for 5th graders. Help me find some items they might enjoy.

This is **not** a simple retrieval query.

It requires:

1. reasoning about what 5th graders might find engaging
2. issuing one or more search queries
3. optionally fetching detailed records
4. assembling the final response

That is a better fit for **agent tool use** than for plain Meilisearch chat workspaces.

---

## Scope

Add an MCP/tool layer that exposes Meilisearch through Symfony AI with a stable, reusable contract.

The bundle should **not** require Meilisearch chat workspaces for this mode.

This MCP mode should work with the existing bundle features:

- configured Meilisearch clients
- configured embedders
- index aliases
- semantic search support
- hybrid search support

---

## Proposed Tool Surface

Expose these tools first.

### 1. `search_index`

Search a single index.

#### Input
- `index`
- `query`
- `limit`
- `filters` (optional)
- `locale` (optional)
- `semantic` (optional)
- `hybrid` (optional)

#### Output
- normalized hits
- score metadata where available
- summary fields only

Use case:

- straightforward retrieval when the agent already knows the target index

---

### 2. `search_indexes`

Search across multiple indexes.

#### Input
- `indexes`
- `query`
- `limit`
- `filters` (optional)
- `locale` (optional)
- `semantic` (optional)
- `hybrid` (optional)

#### Output
- normalized hits grouped by index
- optional merged ranking metadata

Use case:

- cross-collection questions
- broad exploratory retrieval

---

### 3. `get_document`

Fetch one document by ID from one index.

#### Input
- `index`
- `id`

#### Output
- normalized record
- selected metadata fields
- optional raw payload

Use case:

- follow-up detail lookup after search

---

### 4. `similar_documents`

Find documents similar to an existing document.

#### Input
- `index`
- `id`
- `limit`
- `locale` (optional)

#### Output
- similar records
- score / ranking metadata where available

Use case:

- “show me other things like this object”

---

### 5. `search_facets`

Return facet counts for a query.

#### Input
- `index`
- `query`
- `facetAttributes`
- `filters` (optional)

#### Output
- facet buckets
- counts

Use case:

- allowing the agent to understand distribution by type, date, creator, culture, etc.

---

### 6. `resolve_indexes`

Resolve configured logical aliases into actual searchable indexes.

#### Input
- `alias` or `purpose`
- `locale` (optional)
- `tenant` (optional)

#### Output
- resolved index names
- metadata about why they were selected

Use case:

- hide index naming conventions from the agent
- centralize multilingual / tenant-aware routing

---

## Optional Museum-Specific Tools

These should be second-phase tools, and only if implemented generically enough.

### `get_record_label`
Return a display-ready short label for a record.

### `get_record_media`
Return image/media URLs or identifiers.

### `build_source_citation`
Return a citation-friendly text block for a retrieved record.

These should remain optional so the bundle stays generally useful beyond museums.

---

## Service Design

Implement the feature using small focused services.

### `McpToolRegistry`

Registers the MCP-exposed tools and maps them to Symfony AI tool definitions.

---

### `MeiliToolExecutor`

Dispatches tool calls to existing bundle services.

Responsibilities:

- validate tool input
- resolve index aliases
- call Meilisearch
- normalize results

---

### `IndexResolver`

Central place for:

- locale-aware index selection
- alias mapping
- tenant-aware routing
- future multilingual logic

---

### `ResultNormalizer`

Converts raw Meilisearch responses into consistent tool payloads.

Responsibilities:

- trim unsafe fields
- expose meaningful metadata
- shape records for LLM consumption
- keep payloads compact

---

### `MeiliAgentContextBuilder`

Optional helper that builds concise retrieval summaries for agent consumption.

Example output:

- title
- short description
- key metadata
- source identifier

This is especially useful when raw records are too noisy for LLMs.

---

## Symfony AI Integration Strategy

Use Symfony AI as the application-facing tool framework.

The bundle should provide:

- Symfony AI tool definitions
- service wiring
- DTOs for request/response payloads
- optional local MCP exposure via Symfony AI Mate for development workflows :contentReference[oaicite:5]{index=5}

Do **not** hard-code any single LLM provider into this MCP mode.

The agent layer should remain provider-agnostic.

That aligns with Symfony AI’s role as a unified abstraction across providers. :contentReference[oaicite:6]{index=6}

---

## Proposed Bundle Configuration

```yaml
survos_meili:
    mcp:
        enabled: true

        tools:
            search_index: true
            search_indexes: true
            get_document: true
            similar_documents: true
            search_facets: true
            resolve_indexes: true

        defaults:
            limit: 8
            exposeRawDocument: false
            maxSummaryLength: 1200

        aliases:
            collection_objects:
                indexes: ['amst_en', 'wam_en', 'marvel']
            public_catalog:
                indexes: ['catalog_en']

        field_whitelists:
            default:
                - id
                - title
                - description
                - type
                - date
                - creator
                - image
                - url
```

This should be independent from:

- embedders
- Meilisearch chat workspace config

because MCP mode is a separate integration path.

---

## DTOs

Define immutable DTOs for clean tool contracts.

### `SearchIndexInput`
- `index`
- `query`
- `limit`
- `filters`
- `locale`
- `semantic`
- `hybrid`

### `SearchHit`
- `id`
- `index`
- `title`
- `summary`
- `metadata`
- `score`

### `SearchResult`
- `hits`
- `total`
- `facets`
- `resolvedIndexes`

### `DocumentReference`
- `index`
- `id`
- `label`
- `url`

These DTOs should be stable and LLM-friendly.

---

## Console / Developer Support

Add at least one command for testing.

### `bin/console survos:meili:mcp:test`

Examples:

```bash
bin/console survos:meili:mcp:test search_index catalog_en "coins"
bin/console survos:meili:mcp:test search_indexes public_catalog "animals for children"
bin/console survos:meili:mcp:test get_document catalog_en 1234
```

This helps validate tool payloads before wiring them into agents.

---

## Agent Behavior Guidance

When these tools are used by agents, the preferred pattern is:

1. interpret the user’s intent
2. choose a search strategy
3. call one or more Meili tools
4. fetch detail records if needed
5. synthesize the final response
6. include source references where possible

Example:

> I’m preparing a tour for 5th graders.

Possible agent workflow:

1. call `search_indexes` with queries such as:
    - `animals`
    - `weapons`
    - `coins`
    - `brightly colored objects`
    - `objects with stories`
2. call `get_document` for the strongest results
3. produce a short list with explanations tailored to that age group

This is an **agentic retrieval workflow**, not plain conversational search.

---

## Relationship to Native Meilisearch Chat

This feature does **not** replace native Meilisearch chat.

Instead, the bundle should support both.

### Use Meilisearch Chat when:
- you want Meilisearch to own conversational search
- the UX is simple Q&A over indexed content
- you want Meili-managed prompts/workspaces/sources :contentReference[oaicite:7]{index=7}

### Use MCP Tool mode when:
- the task requires planning or reasoning
- the agent may need multiple searches
- the agent needs application-specific abstractions
- you want to avoid wiring Meili chat workspaces

This dual-mode approach should be documented clearly.

---

## Deliverables

### Phase 1
- config tree for `survos_meili.mcp`
- tool DTOs
- result normalizer
- index resolver
- Symfony AI tool definitions
- test command

### Phase 2
- Mate integration for local development
- alias-aware search across multilingual indexes
- museum-safe source formatting
- better facet support

### Phase 3
- optional record/media helper tools
- tenant-aware policies
- richer citation formatting

---

## Non-Goals

Do not:

- require Meilisearch chat workspaces for MCP mode
- hard-code OpenAI or any other single model provider
- introduce LangChain or another orchestration framework
- expose raw Meilisearch payloads by default
- make museum-specific assumptions part of the core generic API

---

## Summary

`survos/meili-bundle` should expose Meilisearch through **Symfony AI tools / MCP-compatible abstractions** so agents can use it for multi-step reasoning workflows.

This gives the bundle two complementary AI modes:

1. **Native Meilisearch Chat**
    - Meilisearch-managed conversational RAG

2. **MCP Tool Mode**
    - agent-managed reasoning over generic Meilisearch tools

That split matches Meilisearch’s own distinction between conversational search and MCP-style integrations, and it fits Symfony AI’s tool-oriented architecture well. :contentReference[oaicite:8]{index=8}
