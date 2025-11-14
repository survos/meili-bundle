// -----------------------------------------------------------------------------
// Survos Meili helpers (framework-agnostic)
// -----------------------------------------------------------------------------

/**
 * Safe JSON.parse with fallback.
 */
export function safeParse(json, fallback) {
  try {
    return json ? JSON.parse(json) : fallback;
  } catch {
    return fallback;
  }
}

/**
 * Strip http/https from a URL (for placeholders / debug).
 */
export function stripProtocol(url) {
  return String(url || '').replace(/^https?:\/\//i, '');
}

/**
 * Basic HTML escaping for debug / fallback rendering.
 */
export function escapeHtml(s) {
  return String(s ?? '')
    .replaceAll(/&/g, '&amp;')
    .replaceAll(/</g, '&lt;')
    .replaceAll(/>/g, '&gt;');
}

/**
 * Normalize a config object (null/undefined → {}).
 */
export function normalizeConfig(cfg) {
  return cfg || {};
}

/**
 * Convert Meili's safe tokens to <mark class="ais-Highlight">…</mark>
 * Example seen in payloads: "__ais-highlight__Dory__/ais-highlight__"
 */
export function convertAisHighlights(text, tag = 'mark') {
  if (typeof text !== 'string') return text;
  return text
    .replaceAll('__ais-highlight__', `<${tag} class="ais-Highlight">`)
    .replaceAll('__/ais-highlight__', `</${tag}>`);
}

/** Deep-walk a hit/_formatted object converting highlight tokens in strings */
export function normalizeFormatted(obj) {
  if (!obj || typeof obj !== 'object') return obj;
  const out = Array.isArray(obj) ? [] : {};
  for (const [k, v] of Object.entries(obj)) {
    if (typeof v === 'string') out[k] = convertAisHighlights(v);
    else if (v && typeof v === 'object') out[k] = normalizeFormatted(v);
    else out[k] = v;
  }
  return out;
}

/**
 * Attach transformItems that hides zero-count facet values.
 * Use when building a RefinementList.
 */
export function withHideZero(options, enabled = true) {
  if (!enabled) return options;
  return {
    ...options,
    transformItems: (items) => items.filter((i) => (i?.count ?? 0) > 0),
  };
}

/**
 * Build fixed range for RangeSlider from index stats (so slider doesn't disable).
 * Expect stats like: stats.numericRanges[attribute] = { min, max }
 */
export function fixedRangeFromStats(stats, attribute) {
  const r = stats?.numericRanges?.[attribute];
  return (r && Number.isFinite(r.min) && Number.isFinite(r.max))
    ? { min: r.min, max: r.max }
    : {};
}

/**
 * Utility to map an array of hits and normalize _formatted highlighting.
 */
export function convertHighlightsInHits(hits, tag = 'mark') {
  return (hits ?? []).map(h => ({
    ...h,
    _formatted: normalizeFormatted(h._formatted ?? {}),
  }));
}
