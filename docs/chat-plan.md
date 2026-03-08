# Plan: Add Conversational Search (Chat) to `survos/meili-bundle`

## Status

`chatCompletions` is still an **experimental feature** in Meilisearch 1.37 — it must be
enabled via `PATCH /experimental-features { "chatCompletions": true }` before chat
endpoints are available. The `meili:settings:update` command handles this automatically.

---

## Architecture

```
Browser (Stimulus chat controller)
        ↕  SSE / chunked HTTP
Symfony proxy endpoint  (MeiliChatController)
        ↕  PSR-7 StreamInterface
Meilisearch  /chats/{workspace}/chat/completions
        ↕  hybrid retrieval
Index documents  →  LLM  →  Answer + Sources
```

The Symfony proxy is mandatory: it keeps the Meilisearch admin key off the client and
works around CORS restrictions.

---

## What the PHP SDK already provides

`meilisearch/meilisearch-php` has full chat support — we do **not** need to implement HTTP
ourselves:

| SDK method | Purpose |
|---|---|
| `$client->chatWorkspace('name')->updateSettings([...])` | Push LLM provider + prompts |
| `$client->chatWorkspace('name')->getSettings()` | Read current workspace config |
| `$client->chatWorkspace('name')->streamCompletion([...])` | Returns PSR-7 `StreamInterface` |
| `$client->getChatWorkspaces()` | List all workspaces |
| Index settings `chat.documentTemplate` | Per-document LLM context template |
| Index settings `chat.documentTemplateMaxBytes` | Token budget per document |

The bundle only needs thin wrappers that read YAML and call the SDK.

---

## Configuration Design

### Global chat config (workspaces)

```yaml
survos_meili:
    chat:
        workspaces:
            product_assistant:
                source: openAi
                apiKey: '%env(OPENAI_API_KEY)%'
                model: gpt-4o-mini          # goes into completion request, NOT workspace settings
                prompts:
                    system: >
                        You are a helpful product assistant.
                        Answer using only the indexed product data.
                        If unsure, say so.
                indexes:
                    - meili_product           # Meilisearch index UIDs
```

**Note:** `model` is a per-request field (sent in the completion body), not a workspace
setting. The workspace settings only hold: `source`, `apiKey`, `prompts`, and provider
extras (`orgId`, `baseUrl`, etc.).

### Per-index chat config

Declared on the MeiliIndex attribute or in YAML under `indexes.*.chat`:

```yaml
survos_meili:
    indexes:
        meili_product:
            chat:
                description: >
                    E-commerce product catalog: titles, brands, categories,
                    prices, ratings, tags, and descriptions.
                documentTemplate: 'templates/liquid/chat/product.liquid'
                documentTemplateMaxBytes: 1000
```

Chat templates are **separate from embedding templates** — they are optimised for LLM
readability, not vector density.

---

## Commands

### `meili:settings:update --chat`

The existing command already handles index settings. Add a `--chat` flag:

```
bin/console meili:settings:update --chat
```

With `--chat`:
1. Enable `chatCompletions` experimental feature (if not already enabled)
2. Push index `chat` settings (documentTemplate, documentTemplateMaxBytes) for each index
3. Push workspace settings (source, apiKey, prompts) for each configured workspace

### `meili:chat:test`

Quick smoke-test from the CLI:

```
bin/console meili:chat:test product_assistant "What are the best gifts under $50?"
```

Outputs streamed answer + source document IDs.

---

## Symfony Proxy Endpoint

A controller that proxies the Meilisearch SSE stream to the browser:

```
GET/POST /meili/chat/{workspace}
```

- Accepts `{ messages: [...], model: 'gpt-4o-mini' }` as JSON body
- Streams the Meilisearch response as `text/event-stream` to the browser
- Parses `_meiliSearchSources` tool calls and emits them as a separate SSE event type
- The Meilisearch admin key never reaches the browser

---

## Streaming SSE Parser

`streamCompletion()` returns a raw PSR-7 `StreamInterface`. The bundle needs a dedicated
parser class `MeiliSseParser` that:

- Reads the stream line by line
- Splits on `data: ` prefix
- Accumulates delta `content` chunks → yields `text` events
- Detects tool calls by `name`:
  - `_meiliSearchProgress` → yield `progress` event
  - `_meiliSearchSources` → yield `sources` event (structured document list)
  - `_meiliAppendConversationMessage` → yield `append_message` event (client must store)
- Handles `[DONE]` sentinel

---

## Stimulus Chat Controller

A `chat_controller.js` Stimulus controller that:

- Holds `messages[]` array in memory across turns (Meilisearch chat is stateless)
- Appends messages received via `_meiliAppendConversationMessage` tool call
- Renders streaming tokens into a message bubble as they arrive
- Shows a "Sources" panel when `_meiliSearchSources` arrives
- Shows a progress indicator during `_meiliSearchProgress`

---

## Implementation Phases

| Phase | What | Files |
|-------|------|-------|
| 1 | Config tree: `chat.workspaces` + `indexes.*.chat` | `SurvosMeiliBundle.php` |
| 2 | Add `--chat` flag to existing `meili:settings:update` — experimental feature + workspace + index chat settings | `Command/MeiliSettingsUpdateCommand.php` |
| 3 | Proxy controller + SSE parser | `Controller/MeiliChatController.php`, `Service/MeiliSseParser.php` |
| 4 | Stimulus chat controller | `assets/src/controllers/chat_controller.js` |
| 5 | `meili:chat:test` CLI command | `Command/MeiliChatTestCommand.php` |

---

## Design Constraints

- Reuse existing Meilisearch SDK client (already wired in bundle)
- Reuse Liquid templating for chat document templates
- No external RAG orchestration (LangChain, etc.)
- Admin key stays server-side; search key goes to browser
- Multiple workspaces supported
- Chat templates kept separate from embedding templates
- Conversation state lives in the Stimulus controller (browser), not server-side

---

## Future Extensions

- **Multi-lingual workspaces**: `product_assistant_en`, `product_assistant_es`
- **Numbered source citations**: `[1] Product SKU-123 – Blue Widget`
- **Agent tool integration**: workspace tools that trigger Symfony routes
- **Dexie persistence**: store conversation history in IndexedDB across page loads
