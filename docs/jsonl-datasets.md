# JSONL Dataset Indexes

For dataset-driven indexes (Fortepan, DC, etc.) that have no backing Doctrine entity,
use `meili:flush-file` with the enriched JSONL from the AI pipeline.

## Full pipeline

```bash
# 1. Normalize raw data
bin/console import:convert --dataset=fortepan/hu

# 2. Import subjects and run AI
bin/console dataset:iterate fortepan/hu
bin/console state:iterate Subject -m prepared -t observe
bin/console messenger:consume ai.subject.observe ai.subject.analyze

# 3. Archive AI claims before any DB reset
bin/console claims:export --dataset=fortepan/hu --include-runs

# 4. Merge normalize + AI claims into enriched JSONL
bin/console import:convert --dataset=fortepan/hu --stage=enrich

# 5. Push to Meilisearch
#    - Prefers 60_enrich over 20_normalize automatically when it exists
#    - --profile-settings derives searchable/filterable/sortable from 21_profile/obj.profile.json
#    - --profile-settings also creates/verifies managed search keys via MeiliServerKeyService
bin/console meili:flush-file fortepan_hu --dataset=fortepan/hu \
    --profile-settings --reset --wait
```

## Profile-derived settings

`--profile-settings` reads `21_profile/obj.profile.json` and infers
searchable/filterable/sortable fields. This is the only way to get sensible settings
for a JSONL-only index (no `#[MeiliIndex]` attribute to derive from).

It also calls `MeiliServerKeyService::ensureServerKeys()` so the index gets the
same managed search keys as entity-backed indexes. No separate `meili:settings:update --keys` step needed.

## Chat

Chat workspace sync (`meili:settings:update --chat`) is not yet automated for JSONL indexes.
Run it manually after flushing:

```bash
bin/console meili:settings:update --chat --force --wait
```

## Rebuild from archive (DB-less recovery)

```bash
bin/console doctrine:schema:update --force
bin/console claims:import --input=/path/to/40_ai/claims.jsonl
bin/console dataset:iterate fortepan/hu
# Restore subject markings from claim sources:
# UPDATE subject SET marking='analyzed', pending_steps='{}' WHERE subject_id IN
#   (SELECT DISTINCT subject_id FROM claim WHERE source LIKE 'extract_metadata%')
bin/console import:convert --dataset=fortepan/hu --stage=enrich
bin/console meili:flush-file fortepan_hu --dataset=fortepan/hu --profile-settings --reset --wait
```
