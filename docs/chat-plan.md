# Plan: Add Conversational Search (Chat) Support to `survos/meili-bundle`

## Goal

Extend `survos/meili-bundle` to support **Meilisearch conversational search (Chat)** on top of the existing semantic search infrastructure.

The bundle already supports:

- Meilisearch indexes
- OpenAI embedders
- Liquid templates for embeddings
- semantic / hybrid retrieval

This work adds:

- **Meilisearch Chat workspaces**
- **per-index chat configuration**
- **chat completions client**
- **conversation state helpers**
- **tool-aware streaming support**

The result is a **native RAG system powered entirely by Meilisearch**, without requiring LangChain, Mediary, or external orchestration.

---

# Architecture Overview

Meilisearch chat adds a layer above semantic search.

```
User Question
     ↓
Meilisearch Chat Endpoint
     ↓
Hybrid Retrieval (semantic + lexical)
     ↓
Document Context
     ↓
LLM Generation
     ↓
Answer + Sources
```

Your existing semantic search configuration already provides the **retrieval layer**.

This work adds:

- **context templates**
- **workspace prompts**
- **conversation orchestration**

---

# Current Bundle Capabilities

The bundle already defines embedders like:

```yaml
survos_meili:
    embedders:
        amst_en_large:
            source: openAi
            model: text-embedding-3-large
            template: templates/liquid/amst.liquid
```

These templates are optimized for **vector embeddings**.

Chat requires **different templates** optimized for:

- LLM readability
- factual grounding
- limited token context

Therefore **chat templates must be separate from embedding templates**.

---

# Required New Concepts

Two new configuration areas must be introduced:

1. **Index Chat Configuration**
2. **Chat Workspaces**

---

# 1. Per-Index Chat Configuration

Each index must declare how its documents are presented to the LLM.

Example:

```yaml
survos_meili:
    indexes:
        amst_objects:
            embedders: ['amst_en_large']

            chat:
                description: >
                    Museum collection records including titles,
                    artists, dates, media, dimensions, provenance,
                    and curatorial notes.

                documentTemplate: templates/liquid/chat/amst_object.liquid

                documentTemplateMaxBytes: 1200
```

### description

Human-readable description of the index.

Used by the LLM to decide whether the index is relevant.

Example:

```
American Studio Glass collection objects including artist names,
dates, materials, and exhibition history.
```

---

### documentTemplate

Liquid template controlling what document content is sent to the LLM.

Example:

```
Title: {{ title }}
Artist: {{ artist }}
Date: {{ date }}
Medium: {{ medium }}

Description:
{{ description }}

Collection: {{ collection }}
Accession: {{ accession_number }}
```

Guidelines:

- include **only fields useful for answering questions**
- avoid large unstructured fields
- include identifying metadata

---

### documentTemplateMaxBytes

Maximum context size per document.

Typical values:

```
800 – 1500 bytes
```

Purpose:

- prevent context overflow
- ensure balanced retrieval

---

# 2. Chat Workspace Configuration

Chat requires **workspace-level configuration**.

A workspace defines:

- LLM provider
- API key
- system prompt
- accessible indexes

Example:

```yaml
survos_meili:
    chat:
        enabled: true

        workspaces:

            museum_guide:

                source: openAi
                apiKey: '%env(OPENAI_API_KEY)%'

                defaultModel: gpt-4.1-mini

                prompts:
                    system: >
                        You are a museum guide.
                        Answer questions using only the indexed records.
                        If the answer is uncertain, say so.

                indexes:
                    - amst_objects
                    - wam_objects


            internal_curatorial:

                source: openAi
                apiKey: '%env(OPENAI_API_KEY)%'

                defaultModel: gpt-4.1

                prompts:
                    system: >
                        You are a curatorial research assistant.
                        Provide detailed, precise answers.
                        Include provenance caveats when metadata is incomplete.

                indexes:
                    - amst_objects
                    - wam_objects
                    - marvel
```

This allows separate conversational behavior for:

- public users
- internal researchers
- curators
- AI agents

---

# Required Services

## MeiliChatManager

Main orchestration service.

Responsibilities:

- call `/chats/{workspace}/chat/completions`
- stream responses
- parse tool events
- return normalized DTOs

---

## MeiliChatWorkspaceManager

Manages workspace configuration.

Responsibilities:

- sync workspace settings
- update prompts
- configure provider keys

Endpoint:

```
PATCH /chats/{workspace}/settings
```

---

## MeiliChatSettingsSynchronizer

Synchronizes index chat configuration.

Responsibilities:

- read bundle config
- push settings to Meilisearch

Endpoint:

```
PATCH /indexes/{uid}/settings
```

---

## MeiliChatFeatureManager

Enables Meilisearch experimental chat feature.

Endpoint:

```
PATCH /experimental-features
{
  "chatCompletions": true
}
```

---

# Required DTOs

Define immutable data structures.

### ChatRequest

```
workspace
messages[]
tools[]
model
temperature
```

---

### ChatMessage

```
role
content
tool_calls?
```

---

### ChatResponseChunk

```
delta
tool_call
finish_reason
```

---

### ChatSourceDocument

Represents documents returned by `_meiliSearchSources`.

```
id
index
score
document
```

---

# Streaming Tool Support

Meilisearch emits special tool calls.

These must be parsed and surfaced.

## `_meiliSearchProgress`

Indicates retrieval / generation progress.

Use to drive UI status updates.

---

## `_meiliAppendConversationMessage`

Used to maintain conversation state.

Client must append the message to the conversation history.

---

## `_meiliSearchSources`

Returns retrieved documents.

These should be surfaced to the UI as:

```
Sources:
 - Object 1892
 - Catalog Entry 1941
 - Exhibit Record
```

---

# Conversation State

Meilisearch chat is **stateless**.

Conversation must be preserved client-side.

Bundle should implement:

### ChatConversation

```
id
workspace
messages[]
```

---

### ChatTurn

```
userMessage
assistantMessage
sources[]
toolCalls[]
```

---

# Symfony Console Commands

Add two commands.

---

## Sync Chat Configuration

```
bin/console survos:meili:chat:sync
```

Responsibilities:

- enable experimental feature
- sync index chat settings
- sync workspace settings

---

## Test Chat Workspace

```
bin/console survos:meili:chat:test museum_guide "Who created this object?"
```

Outputs:

```
Answer:
  ...

Sources:
  amst_objects/1234
  amst_objects/5678
```

---

# Implementation Steps

## Phase 1 — Configuration

Add config tree:

```
survos_meili.chat
survos_meili.indexes.*.chat
```

---

## Phase 2 — Settings Synchronization

Implement:

```
MeiliChatSettingsSynchronizer
MeiliChatWorkspaceManager
```

---

## Phase 3 — Chat Client

Implement:

```
MeiliChatManager
```

Responsibilities:

- send requests
- stream responses
- parse tool calls

---

## Phase 4 — Conversation Helpers

Add DTOs:

```
ChatConversation
ChatMessage
ChatTurn
```

---

## Phase 5 — Developer Tools

Add commands:

```
survos:meili:chat:sync
survos:meili:chat:test
```

---

# Design Constraints

- reuse existing HTTP client
- reuse Liquid templating
- no external RAG orchestration
- support multiple workspaces
- keep chat templates separate from embedding templates
- expose normalized Symfony-friendly events

---

# Expected Result

After implementation:

```
User Question
      ↓
survos/meili-bundle
      ↓
Meilisearch Chat Workspace
      ↓
Hybrid Retrieval
      ↓
LLM Generation
      ↓
Answer + Source Documents
```

The bundle becomes a **native conversational search layer for Symfony applications**.

---

# Future Extensions

Possible improvements after initial implementation:

### 1. Multi-lingual chat workspaces

```
museum_guide_en
museum_guide_es
museum_guide_fr
```

---

### 2. Source citation formatting

Return structured citations:

```
[1] Object 1892 – American Studio Glass
[2] Exhibition Catalog 1974
```

---

### 3. Agent tool integration

Allow chat responses to trigger:

```
pixie:fetch-object
pixie:show-image
pixie:open-record
```

---

### 4. UI components

Symfony UX components:

```
ChatSourcesPanel
ChatProgressIndicator
ChatConversationView
```

---

# Summary

To support Meilisearch Chat, `survos/meili-bundle` must add:

- index-level **chat templates**
- workspace-level **LLM configuration**
- a **chat completions client**
- **conversation state helpers**
- **tool-aware streaming support**

This will transform the bundle from a **semantic search layer** into a **full conversational retrieval system**.
