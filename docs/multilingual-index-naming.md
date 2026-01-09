# Multi-lingual index naming strategy

This bundle separates **configuration keys** from **Meilisearch index UIDs**.

## Terminology

- **base name** (`baseName`)
  - The stable key used in configuration and in `$meiliService->settings` / `$meiliService->getIndexSetting()`.
  - Example: `amst`

- **raw index name** (unprefixed)
  - The base name optionally suffixed with `_{locale}` when multilingual is enabled.
  - Examples: `amst`, `amst_en`, `amst_nl`

- **UID** (Meilisearch index UID)
  - The value actually sent to Meilisearch.
  - It is the *raw index name* with an optional configured prefix applied.
  - Example with prefix `bts_`: `bts_amst_en`

## Resolution rules

The canonical resolver is `Survos\MeiliBundle\Service\IndexNameResolver`.

- Multilingual mode is enabled when either:
  - `meili.multiLingual` is enabled in config, or
  - at least one registered base index declares locale targets in registry metadata.

- When multilingual mode is enabled and a locale is provided:
  - `raw = "{base}_{locale}"` (locale lowercased)

- Otherwise:
  - `raw = "{base}"`

- The final Meilisearch UID is:
  - `uid = "{prefix}{raw}"` (prefix applied once, if configured and not already present)

## API you should use

- Base + locale → UID (recommended for all Meilisearch API calls)
  - `MeiliService::uidForBase($baseName, $locale)`

- Base + locale → raw (unprefixed)
  - `MeiliService::rawForBase($baseName, $locale)`

- Raw → UID (prefix only)
  - `MeiliService::uidForRaw($rawName)`

### Backwards compatibility

Some older call sites use `MeiliService::localizedUid($baseName, $locale)`.
This is now an alias for `uidForBase()`.

## Common pitfall

Do **not** use the resolved UID as the key for settings lookup.

- Settings are keyed by base name: `$settings = $meiliService->settings[$baseName] ?? []`
- Meilisearch endpoints are addressed by UID: `$indexApi = $meiliService->getIndexEndpoint($uid)`
