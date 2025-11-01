/**
 * File: assets/src/debug/meili_sanity.js
 * Version: MEILI-SANITY v1.1
 * Purpose: Console-only diagnostics using the official Meilisearch JS client.
 * Notes: This never blocks or configures the UI; purely informational.
 */
import { MeiliSearch } from 'meilisearch';

const log = (...args) => console.debug('[MEILI sanity]', ...args);

/**
 * @param {{host:string, apiKey?:string|null, indexUid:string, query?:string}} cfg
 */
export async function runMeiliSanity(cfg) {
  const { host, apiKey = null, indexUid, query = '' } = cfg;
  if (!host || !indexUid) {
    console.warn('[MEILI sanity] missing host or indexUid', { host, indexUid });
    return { ok: false, error: 'Missing host or indexUid' };
  }

  const client = new MeiliSearch({ host, apiKey: apiKey ?? undefined });
  log('client created', { host, indexUid, hasKey: Boolean(apiKey) });

  try {
    const version = await client.getVersion();
    log('version', version);
  } catch (e) {
    console.error('[MEILI sanity] getVersion failed', e);
    return { ok: false, error: 'Cannot reach Meilisearch (getVersion)', cause: e };
  }

  const index = client.index(indexUid);

  try {
    const info = await index.getRawInfo();
    log('index info', info);
  } catch (e) {
    console.error('[MEILI sanity] index.getRawInfo failed (index missing?)', e);
    return { ok: false, error: `Index "${indexUid}" not found or inaccessible`, cause: e };
  }

  let settings;
  try {
    settings = await index.getSettings();
    const {
      displayedAttributes,
      searchableAttributes,
      filterableAttributes,
      sortableAttributes,
      rankingRules,
      faceting,
      distinctAttribute,
    } = settings;

    log('settings.displayed', displayedAttributes);
    log('settings.searchable', searchableAttributes);
    log('settings.filterable', filterableAttributes);
    log('settings.sortable', sortableAttributes);
    log('settings.rankingRules', rankingRules);
    log('settings.faceting', faceting);
    log('settings.distinctAttribute', distinctAttribute);

    if (!Array.isArray(filterableAttributes)) {
      console.warn('[MEILI sanity] filterableAttributes is not an array!', filterableAttributes);
    }
  } catch (e) {
    console.error('[MEILI sanity] index.getSettings failed', e);
    return { ok: false, error: 'Unable to fetch index settings', cause: e };
  }

  try {
    const stats = await index.getStats();
    log('stats', stats);
  } catch (e) {
    console.warn('[MEILI sanity] index.getStats failed (not fatal)', e);
  }

  try {
    const facetAttrs = Array.isArray(settings.filterableAttributes) ? settings.filterableAttributes : [];
    const res = await index.search(query, facetAttrs.length ? { facets: facetAttrs } : {});
    log('search ok', {
      query,
      estimatedTotalHits: res.estimatedTotalHits,
      facetDistribution: res.facetDistribution ?? null,
    });
  } catch (e) {
    console.error('[MEILI sanity] search failed', e);
    return { ok: false, error: 'Search failed — check your facets param type (must be array)', cause: e };
  }

  log('sanity check completed');
  return { ok: true };
}
