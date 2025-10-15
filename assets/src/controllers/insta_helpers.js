/** ---------- Stable helpers: do not churn ---------- */

/** Safe JSON parse with fallback */
export const safeParse = (s, fallback) => { try { return JSON.parse(s) } catch { return fallback } };

/** Small string helpers */
export const stripProtocol = (u) => (u || '').replace(/(^\w+:|^)\/\//, '');
export const escapeHtml = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

/** Facets that are tiny enums: default non-searchable unless overridden */
export const DEFAULT_NON_SEARCHABLE = new Set(['gender', 'marking', 'house', 'currentParty', 'countries', 'locale']);

/** Named formatters for RangeSlider tooltips, referenced by name via data-tooltips='{"format":"monthIndex"}' */
export const FORMATTERS = {
  monthIndex(i) {
    const year = Math.floor(i / 12), month = i % 12;
    return new Date(year, month, 1).toLocaleString('default', { year: 'numeric', month: 'short' });
  },
  int(v) { return Math.round(Number(v) || 0); },
  usd(v) { return '$' + new Intl.NumberFormat().format(Number(v) || 0); },
};

/** Turn string options (data-*) into proper booleans/numbers and resolve tooltip formatters */
export function coerceWidgetOptions(widgetName, opts) {
  const out = { ...opts };
  if (out.tooltips && typeof out.tooltips === 'object') {
    const fmt = out.tooltips.format;
    if (typeof fmt === 'string' && FORMATTERS[fmt]) {
      out.tooltips = { ...out.tooltips, format: FORMATTERS[fmt] };
    }
  }
  for (const k of ['pips','searchable','showMore']) {
    if (k in out && typeof out[k] === 'string') out[k] = out[k] === 'true';
  }
  for (const k of ['step','min','max','limit','showMoreLimit']) {
    if (k in out && typeof out[k] === 'string') {
      const n = Number(out[k]); if (!Number.isNaN(n)) out[k] = n;
    }
  }
  return out;
}

/** Accept facets as array or map; default widget = RefinementList */
export function normalizeConfig(raw) {
  const cfg = (raw && typeof raw === 'object') ? { ...raw } : {};
  if (cfg.facets && !Array.isArray(cfg.facets) && typeof cfg.facets === 'object') {
    cfg.facets = Object.entries(cfg.facets).map(([attribute, f]) => {
      const o = (f && typeof f === 'object') ? { ...f } : {};
      o.attribute = attribute;
      o.widget = typeof o.widget === 'string' ? o.widget
               : (typeof o.type === 'string' ? o.type : 'RefinementList');
      return o;
    });
  }
  if (!Array.isArray(cfg.facets)) cfg.facets = [];
  return cfg;
}

/** Minimal label transformer used by facets */
export function transformWithLookup(ctrl, items, attribute, lookup) {
  if (attribute === 'locale') {
    return items.map(it => ({ ...it, highlighted: ctrl.languageNames.of(it.value.toUpperCase()) }));
  }
  if (attribute === 'countries' || attribute === 'countryCode') {
    return items.map(it => ({ ...it, highlighted: ctrl.regionNames.of(it.value.toUpperCase()) }));
  }
  if (!lookup || Object.keys(lookup).length === 0) return items;
  return items.map(it => ({ ...it, highlighted: lookup[it.value] || it.value }));
}
