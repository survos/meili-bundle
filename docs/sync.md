# Doctrine Auto-Sync

The bundle keeps your Meilisearch indexes in sync with your database automatically.
When an entity with `#[MeiliIndex]` is persisted, updated, or deleted through Doctrine,
the change is reflected in Meilisearch — no manual calls required.

## How it works

1. **`DoctrineEventListener`** listens to `postPersist`, `postUpdate`, `postFlush`, and `preRemove`.
2. On change, it dispatches a `BatchIndexEntitiesMessage` (or `BatchRemoveEntitiesMessage`) to the
   Symfony Messenger bus.
3. The message handler normalizes the entity and calls `addDocuments()` / `deleteDocuments()`
   on the Meilisearch index.

## Synchronous vs asynchronous

By default messages are handled **synchronously** (the Messenger `sync` transport).
This means every `$em->flush()` immediately indexes the changed entities.

For production with large entities or high write throughput, route the messages to an
async transport (database, Redis, etc.):

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            'Survos\MeiliBundle\Message\BatchIndexEntitiesMessage': async
            'Survos\MeiliBundle\Message\BatchRemoveEntitiesMessage': async
```

Then run the worker:

```bash
bin/console messenger:consume async
```

## Batching

Messages are **batched** by the handler: multiple entity changes from a single `flush()`
are grouped into one `addDocuments()` call, keeping Meilisearch API calls minimal.

## Disabling auto-sync per entity

Set `autoIndex: false` on the attribute when you control indexing manually:

```php
#[MeiliIndex(autoIndex: false)]
class ImportedRow { ... }
```

The Doctrine listener will ignore this entity entirely. You can still populate it with
`bin/console meili:populate importedrow --all`.

## Disabling the listener globally

If you do not want any automatic sync, remove the `DoctrineEventListener` service registration
by overriding it in `services.yaml`:

```yaml
# config/services.yaml
Survos\MeiliBundle\EventListener\DoctrineEventListener:
    tags: []   # removes the doctrine.event_listener tags
```

## Entity removal

When an entity is deleted (`preRemove`), the listener dispatches `BatchRemoveEntitiesMessage`
with the entity's primary key. The handler calls `deleteDocuments()` on the index.

If you use soft-delete (e.g. `gedmo/doctrine-extensions` `SoftDeleteable`), the entity
is not hard-deleted, so `preRemove` is not fired. In that case you can hook into the
`postUpdate` event and check whether the `deletedAt` field was just set, then manually
dispatch the removal message, or simply re-run `meili:populate` periodically.

## Checking sync status

```bash
bin/console meili:registry:report
bin/console meili:registry:sync   # refresh IndexInfo stats from live Meilisearch
```

## What gets indexed

The document payload is built by `MeiliPayloadBuilder`, which:

1. Uses the Symfony Serializer with the groups declared in `#[MeiliIndex(persisted: ...)]`.
2. Falls back to `PropertyAccess` for simple top-level fields that lack `#[Groups]`.
3. Strips Meili metadata fields (`_rankingScore`, `_vectors`, etc.) from the output.

If a field you expect is not appearing in the index, check:
- Is the field in `persisted` (or `searchable`/`filterable`)?
- Does the field have the correct `#[Groups]` annotation if your entity uses groups?
- Run `bin/console meili:mcp:test get_document meili_product <id>` to inspect the stored document.
