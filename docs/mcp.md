# MCP / AI Agent Integration

This bundle exposes your Meilisearch indexes as tools that any MCP-compatible AI client can use —
Claude Desktop, ChatGPT, Cursor, Zed, n8n, or anything else that speaks the
[Model Context Protocol](https://modelcontextprotocol.io/).

**No Python. No `pip install`. No separate process.**
Your Symfony app *is* the MCP server.

## What the tools do

| Tool | Description |
|---|---|
| `meili_search_index` | Keyword or hybrid (semantic + keyword) search on an index |
| `meili_get_document` | Fetch a single document by primary key |
| `meili_similar_documents` | Vector similarity search around a document |
| `meili_search_facets` | Facet distribution counts; always returns `availableFilterable` so the agent knows what's valid |

## Setup

### 1. Install the AI and MCP bundles

```bash
composer require symfony/ai-bundle symfony/mcp-bundle
```

### 2. Add the MCP route

```yaml
# config/routes/mcp.yaml
mcp:
    resource: .
    type: mcp
```

### 3. Configure the MCP server

```yaml
# config/packages/mcp.yaml
mcp:
    app: 'my-app'
    version: '1.0.0'
    description: 'Search my Meilisearch data via natural language.'
    client_transports:
        http: true
    http:
        path: /_mcp   # default — change if needed
```

The four tools are auto-registered via `#[McpTool]` attribute autoconfiguration.
No tool registration code required.

## Connecting AI clients

The MCP endpoint is `https://your-app.example.com/_mcp`.

### Claude Desktop

Edit `claude_desktop_config.json`:
- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "my-app": {
      "transport": "http",
      "url": "https://your-app.example.com/_mcp"
    }
  }
}
```

Restart Claude Desktop. You can now ask:
> "Search the product index for wireless headphones under $100"
> "What categories have the most laptops?"
> "Find products similar to SKU ABC-123"

### ChatGPT

In your custom GPT or agent configuration, add a new action, select MCP as the schema
type, and point it at `https://your-app.example.com/_mcp`.

### Cursor / Zed / other clients

Add an MCP server entry pointing to `https://your-app.example.com/_mcp` using HTTP
transport. Refer to your client's MCP documentation for the exact config format.

### n8n

Use the Docker image approach or point an HTTP MCP node at `/_mcp`.
The endpoint is stateless HTTP so any client that supports streamable HTTP MCP works.

### MCP Inspector (debug / test)

```bash
npx @modelcontextprotocol/inspector
```

Enter `https://your-app.example.com/_mcp` as the server URL. You will see all four tools
listed with their full JSON schemas and can invoke them interactively.

## Profiler panel

When the Symfony Web Profiler is enabled, `symfony/mcp-bundle` adds an **MCP panel** to
the toolbar showing all registered tools with their descriptions and input schemas.
The closest thing to an API Platform docs browser for your tools.

## Hybrid search via MCP

The `meili_search_index` tool accepts an optional `embedder` parameter. Configure it
in `ai.yaml` system prompt so the agent always uses it:

```yaml
# config/packages/ai.yaml
ai:
    agents:
        product_assistant:
            model: { name: gpt-4o }
            prompt: >
                You are a helpful product search assistant.
                Always use the product embedder for hybrid search.
                When searching, pass embedder=product and semanticRatio=0.6.
            tools:
                - Survos\MeiliBundle\Tool\SearchIndexTool
                - Survos\MeiliBundle\Tool\GetDocumentTool
                - Survos\MeiliBundle\Tool\SimilarDocumentsTool
                - Survos\MeiliBundle\Tool\SearchFacetsTool
```

Then test interactively:

```bash
bin/console ai:agent:call product_assistant
# or
bin/console ai:chat product_assistant
```

## Smoke-testing from the CLI

```bash
bin/console meili:mcp:test search_index meili_product "wireless headphones"
bin/console meili:mcp:test search_index meili_product "cool gifts for techies" \
    --embedder=product --semantic-ratio=0.6
bin/console meili:mcp:test search_facets meili_product "laptop" --extra="category,brand"
bin/console meili:mcp:test get_document meili_product ABC-123
bin/console meili:mcp:test similar_documents meili_product ABC-123 --extra=product
```

## How tool descriptions work

The LLM receives two pieces of information about each tool:

1. **Short description** — the second argument of `#[AsTool(...)]` on the tool class.
2. **Parameter descriptions** — the `@param` docblock lines on `__invoke()`.

Both are in the tool source files under `src/Tool/`. If you want to change what the agent
sees, those are the places to edit.

## Security

The `/_mcp` endpoint is served by your Symfony app so your normal firewall rules apply.
Add authentication as appropriate for your use case — for example, require an API token
for production endpoints or restrict to `localhost` for development.

## Comparison with the Python `meilisearch-mcp` package

The official Python package (`pip install meilisearch-mcp`) is a general-purpose
Meilisearch *admin* tool: it can create indexes, manage API keys, monitor tasks, etc.

This bundle is narrower but better integrated:

| | Python `meilisearch-mcp` | This bundle |
|---|---|---|
| Installation | `pip install` / Docker | `composer require` |
| Process | Separate stdio process | Your Symfony app |
| Transport | stdio | HTTP |
| Scope | Full admin API (~20 tools) | Search-focused (4 tools) |
| Connection | Dynamic (agent sets host in chat) | Fixed to app's Meilisearch |
| Symfony groups / auth | None | Full Symfony security stack |
