/**
 * File: assets/src/controllers/insta_controller.js
 * Version: MEILI-INSTA v6.4.3
 *
 * Keys:
 *  - Deep-convert __ais-highlight__ markers across all hit fields (strings anywhere).
 *  - Keep Instant Meilisearch as the single source of truth.
 *  - Seed meiliSearchParams.facets before client creation.
 *  - keepZeroFacets=true so facets render on first paint.
 *  - Robust parsing of data-limit / data-show-more-limit ("" -> undefined).
 *  - showReset=true on SearchBox.
 *  - Hide zero-count facet values via transformItems.
 */

import { Controller } from '@hotwired/stimulus';
import * as StimAttrs from 'stimulus-attributes';
import instantsearch from 'instantsearch.js';
import { instantMeiliSearch } from '@meilisearch/instant-meilisearch';
import { MeiliSearch } from 'meilisearch';
import { runMeiliSanity } from '../debug/meili_sanity.js';
import { withHideZero } from './insta_helpers.js';

// AI: NEVER ELIMINATE OR MODIFY THESE LINES!, they are very important
import Twig from 'twig';
import { installTwigAPI } from './insta_twig.js';
let Routing = null;
try {
  const module = await import('fos-routing');
  Routing = module.default;
  const RoutingDataModule = await import('/js/fos_js_routes.js');
  const RoutingData = RoutingDataModule.default;
  Routing.setData(RoutingData);
} catch { /* optional */ }
installTwigAPI({ Routing, StimAttrs });
// END OF IMPORTANT CODE

import '../debug/fetch_tap.js';
import { createDebug } from '../debug/debug.js';
import {
  searchBox,
  infiniteHits,
  stats,
  clearRefinements,
  hitsPerPage,
  sortBy,
  configure,
  refinementList,
  rangeSlider
} from 'instantsearch.js/es/widgets';

const safeParse = (json, fallback) => { try { return json ? JSON.parse(json) : fallback; } catch { return fallback; } };
const stripProtocol = (url) => String(url || '').replace(/^https?:\/\//i, '');
const escapeHtml = (s) => String(s ?? '').replaceAll(/&/g,'&amp;').replaceAll(/</g,'&lt;').replaceAll(/>/g,'&gt;');
const normalizeConfig = (cfg) => cfg || {};

const logInsta  = createDebug('insta:core');
const logView   = createDebug('view:render');
const logFacets = createDebug('insta:facets');
const hard = (...a) => console.warn('[INSTA]', ...a);

const HLPRE  = '__ais-highlight__';
const HLPOST = '__/ais-highlight__';

// ---------- highlight helpers ----------
function swapMarkersToHtml(s) {
  const txt = String(s ?? '');
  return txt.replaceAll(HLPRE, '<mark class="ais-Highlight">')
            .replaceAll(HLPOST, '</mark>');
}

/** Deeply map all strings in a value with a transformer fn */
function mapDeepStrings(value, fn) {
  if (typeof value === 'string') return fn(value);
  if (Array.isArray(value)) return value.map(v => mapDeepStrings(v, fn));
  if (value && typeof value === 'object') {
    const out = Array.isArray(value) ? [] : {};
    for (const [k, v] of Object.entries(value)) out[k] = mapDeepStrings(v, fn);
    return out;
  }
  return value;
}

/** Build AIS-like _highlightResult from a (possibly converted) _formatted object */
function buildHighlightResultFromFormatted(formatted) {
  const out = {};
  for (const [key, val] of Object.entries(formatted || {})) {
    if (typeof val === 'string' || typeof val === 'number' || typeof val === 'boolean') {
      out[key] = { value: String(val) };
    } else if (Array.isArray(val)) {
      out[key] = val.map(v =>
        (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean')
          ? { value: String(v) }
          : { value: swapMarkersToHtml(JSON.stringify(v)) }
      );
    } else if (val && typeof val === 'object') {
      // Render nested objects as JSON but preserve <mark> tags in any strings
      out[key] = { value: swapMarkersToHtml(JSON.stringify(val)) };
    }
  }
  return out;
}

/** Convert all highlight markers in-place and return the hit */
function convertHighlightInHit(hit) {
  // 1) If Meili gave _formatted, convert it deeply first.
  if (hit && hit._formatted && typeof hit._formatted === 'object') {
    hit._formatted = mapDeepStrings(hit._formatted, swapMarkersToHtml);
  }
  // 2) Convert ALL string fields in the hit (anywhere) to ensure markers never leak.
  //    This is safe because swapMarkersToHtml() is a no-op for strings without markers.
  const converted = mapDeepStrings(hit, swapMarkersToHtml);

  // 3) Provide/refresh _highlightResult for consumers that expect it.
  if (converted._formatted && typeof converted._formatted === 'object') {
    converted._highlightResult = buildHighlightResultFromFormatted(converted._formatted);
  }

  return converted;
}

// Parse numeric data-attrs: empty -> undefined, invalid -> undefined
function parseDataNumber(value) {
  if (value === undefined || value === null) return undefined;
  const trimmed = String(value).trim();
  if (trimmed === '') return undefined;
  const n = Number(trimmed);
  return Number.isFinite(n) ? n : undefined;
}

export default class extends Controller {
  static targets = [
    'searchBox','hits','reset','refinementList','stats',
    'sortBy','debug','notice','pagination',
    'semanticSlider','semanticOutput','scoreMultiplier','scoreThreshold'
  ];

  static values = {
    serverUrl: String,
    serverApiKey: String,
    indexName: String,
    templateUrl: String,
    userLocale: { type: String, default: 'en' },
    q: { type: String, default: '' },
    hitClass: { type: String, default: 'grid-3' },
    globalsJson: { type: String, default: '{}' },
    iconsJson: { type: String, default: '{}' },
    sortingJson: { type: String, default: '[]' },
    configJson: { type: String, default: '{}' },
    searchAsYouType: { type: Boolean, default: true },
    sanity: { type: Boolean, default: false }
  };

  initialize() {
    hard('MEILI-INSTA v6.4.3 boot', { index: this.indexNameValue, url: this.serverUrlValue });
    try { if (!localStorage.getItem('debug')) localStorage.setItem('debug', 'insta:*'); } catch {}

    this.globals = safeParse(this.globalsJsonValue, {});
    this.icons   = safeParse(this.iconsJsonValue, {});
    window.__survosIconsMap = this.icons || {};

    this.sorting = safeParse(this.sortingJsonValue, []);
    this.config  = normalizeConfig(safeParse(this.configJsonValue, {}));

    this.template = null;
    this._facetAttrs = [];
    this._facetRenderChecks = 0;
    this._facetWarnedOnce = false;
    this._lastFacetKeys = [];

    this._meiliOptions = {
      primaryKey: undefined,
      keepZeroFacets: true,
      meiliSearchParams: {
        keepZeroFacets: true,
        showRankingScore: true,
        showRankingScoreDetails: true,
        highlightPreTag:  HLPRE,
        highlightPostTag: HLPOST
      }
    };

    this._setMeiliParams = null;
  }

  async connect() {
    await this._loadTemplate();

    this._facetAttrs = this._collectFacetAttributes();
    hard('facet attrs requested', this._facetAttrs);

    if (Array.isArray(this._facetAttrs) && this._facetAttrs.length) {
      this._meiliOptions.meiliSearchParams.facets = this._facetAttrs;
    }
    this._meiliOptions.keepZeroFacets = true;

    await this._startSearch();

    const host = this.serverUrlValue;
    const apiKey = this.serverApiKeyValue || null;
    const indexUid = this.indexNameValue;
    window.meiliSanity = () => runMeiliSanity({ host, apiKey, indexUid, query: '' });
    if (this.sanityValue || localStorage.getItem('meili:debug') === '1') {
      runMeiliSanity({ host, apiKey, indexUid, query: '' }).catch(() => {});
    }
  }

  disconnect() { try { this.search?.dispose(); } catch {} this.search = null; }

  async _loadTemplate() {
    if (!this.templateUrlValue) return;
    const res = await fetch(this.templateUrlValue);
    if (!res.ok) throw new Error(`Template HTTP ${res.status}: ${res.statusText}`);
    const ct = res.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await res.json() : await res.text();
    if (!Twig || typeof Twig.twig !== 'function') throw new Error('Twig.twig() not available; ensure browser build of "twig" is used.');
    this.template = Twig.twig({ data });
  }

  _createClient() {
    const { searchClient, setMeiliSearchParams } = instantMeiliSearch(
      this.serverUrlValue,
      this.serverApiKeyValue || undefined,
      this._meiliOptions
    );
    this._setMeiliParams = setMeiliSearchParams;
    return searchClient;
  }

  async _startSearch(initialUiState = null) {
    const ui = initialUiState ?? {
      [this.indexNameValue]: { query: (this.qValue || '').trim() || undefined }
    };

    const is = instantsearch({
      indexName: this.indexNameValue,
      searchClient: this._createClient(),
      routing: this.config?.instantsearch?.routing ?? true,
      insights: this.config?.instantsearch?.insights ?? false,
      initialUiState: ui
    });

    this.search = is;
    window.search = is;

    const widgets = [
      configure({
        facets: this._facetAttrs,
        attributesToHighlight: ['*'],
        highlightPreTag:  HLPRE,
        highlightPostTag: HLPOST
      }),

      searchBox({
        container: this.searchBoxTarget,
        searchAsYouType: this.hasSearchAsYouTypeValue ? !!this.searchAsYouTypeValue : true,
        placeholder: `${this.indexNameValue} on ${stripProtocol(this.serverUrlValue)} ${this.qValue}`,
        autofocus: false,
        showReset: true,
        showSubmit: true
      }),

      ...(this.hasStatsTarget ? [stats({ container: this.statsTarget })] : []),

      ...(this.hasResetTarget ? [clearRefinements({
        container: this.resetTarget,
        clearsQuery: false,
        templates: { reset: 'Clear refinements' },
        escapeHTML: false
      })] : []),

      hitsPerPage({
        container: document.createElement('div'),
        items: [
          { value: 10, label: '10 / page' },
          { value: 20, label: '20 / page', default: true },
          { value: 50, label: '50 / page' }
        ]
      }),

      ...(this.hasHitsTarget ? [infiniteHits({
        container: this.hitsTarget,
        escapeHTML: false,
        cssClasses: {
          item: this.hitClassValue || 'grid-3',
          loadMore: 'btn btn-primary',
          disabledLoadMore: 'btn btn-secondary disabled'
        },
        transformItems: (items) => items.map((h) => convertHighlightInHit(h)),
        templates: {
          item: (hit) => {
            const ctx = { hit, icons: this.icons, globals: this.globals, _config: this.config };
            const body = this.template ? this.template.render(ctx) : `<pre>${escapeHtml(JSON.stringify(hit, null, 2))}</pre>`;
            logView('rendered hit (first 400) → %o', body?.slice?.(0, 400) ?? body);
            return `<div class="insta-hit">${body}</div>`;
          },
          empty: () => `<div class="text-muted">No results.</div>`
        }
      })] : [])
    ];

    if (this.hasSortByTarget) {
      widgets.push(sortBy({ container: this.sortByTarget, items: this.sorting }));
    }

    // Mount facet widgets declared in the template
    const facetNodes = this.refinementListTarget?.querySelectorAll?.('[data-attribute][data-widget]') || [];
    facetNodes.forEach(el => {
      if (el.dataset.mounted === '1') return;
      this._mountFacetFromNode(is, el);
    });

    this._wireFacetCollapsers();

    is.addWidgets(widgets);
    is.start();

    is.on('render', () => {
      try {
        this._facetRenderChecks++;
        this._logFacetDiagnostics(is.helper, this._facetAttrs);
        this._updateDebug(is.helper);
      } catch (e) {
        logFacets('render hook error → %o', e?.message || e);
        hard('render hook error', e?.message || e);
      }
    });
  }

  _facetSort(mode) {
    const m = String(mode || '').toLowerCase();
    if (m === 'count') return ['isRefined', 'count:desc', 'name:asc'];
    if (m === 'alpha') return ['isRefined', 'name:asc'];
    return ['isRefined', 'count:desc', 'name:asc'];
  }

  _mountRefinementList(is, el, attribute) {
    let limit = parseDataNumber(el.dataset.limit);
    let showMoreLimit = parseDataNumber(el.dataset.showMoreLimit);
    if (!Number.isFinite(limit) || limit <= 0) limit = 10;
    if (!Number.isFinite(showMoreLimit) || showMoreLimit <= limit) {
      showMoreLimit = Math.max(limit + 10, limit * 2);
    }

    const options = {
      container: el,
      attribute,
      searchable: el.dataset.searchable !== 'false',
      searchablePlaceholder: `Search ${attribute}…`,
      sortBy: this._facetSort(el.dataset.sortMode),
      limit,
      showMore: true,
      showMoreLimit,
      transformItems: (items) => withHideZero({}, true).transformItems?.(items) ?? items
    };

    is.addWidgets([ refinementList(options) ]);
    el.dataset.mounted = '1';
    logFacets('mounted refinementList %o (limit=%o showMoreLimit=%o sort=%o searchable=%o)',
      attribute, limit, showMoreLimit, options.sortBy, options.searchable);
  }

  _mountRangeSlider(is, el, attribute) {
    const min = parseDataNumber(el.dataset.min);
    const max = parseDataNumber(el.dataset.max);
    const step = parseDataNumber(el.dataset.step);

    const opts = {
      container: el,
      attribute,
      ...(Number.isFinite(min) ? { min } : {}),
      ...(Number.isFinite(max) ? { max } : {}),
      ...(Number.isFinite(step) ? { step } : {}),
      tooltips: (el.dataset.tooltips ? safeParse(el.dataset.tooltips, true) : true),
      pips: el.dataset.pips === 'true',
      searchOnChange: false
    };
    is.addWidgets([ rangeSlider(opts) ]);
    el.dataset.mounted = '1';
    logFacets('mounted rangeSlider(drop-only) %o', attribute);
  }

  _mountFacetFromNode(is, el) {
    const attribute = (el?.dataset?.attribute || '').trim();
    const widget    = (el?.dataset?.widget    || '').trim() || 'RefinementList';
    if (!attribute) return;

    if (widget.toLowerCase() === 'refinementlist') return this._mountRefinementList(is, el, attribute);
    if (widget.toLowerCase() === 'rangeslider')    return this._mountRangeSlider(is, el, attribute);

    logFacets('facet widget "%s" for %o not recognized; skipping', widget, attribute);
  }

  _collectFacetAttributes() {
    const nodes = this.refinementListTarget?.querySelectorAll?.('[data-attribute][data-widget]') || [];
    const attrs = Array.from(nodes).map(el => (el.dataset.attribute || '').trim()).filter(Boolean);
    return Array.from(new Set(attrs));
  }

  _wireFacetCollapsers() {
    const root = this.refinementListTarget;
    if (!root) return;
    const buttons = root.querySelectorAll('[data-collapse-control]');
    buttons.forEach(btn => {
      if (btn.dataset.bound === '1') return;
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const attribute = btn.getAttribute('data-attribute') || '';
        const block = btn.closest('[data-facet-block]');
        const body  = block?.querySelector('.facet-body');
        if (!body) return;
        const expanded = (btn.getAttribute('aria-expanded') || 'true') === 'true';
        const next = !expanded;
        btn.setAttribute('aria-expanded', String(next));
        body.style.display = next ? '' : 'none';
        logFacets('facet "%s" collapsed=%o', attribute, !next);
      });
      btn.dataset.bound = '1';
    });
  }

  _updateDebug(helper) {
    if (!this.hasDebugTarget || !helper) return;
    const st = helper.state;
    const dbg = {
      tag: 'INSTA_HELPER_STATE',
      index: st.index,
      query: st.query || '',
      page: st.page,
      hitsPerPage: st.hitsPerPage,
      attributesToHighlight: st.attributesToHighlight || ['*'],
      highlightPreTag: st.highlightPreTag,
      highlightPostTag: st.highlightPostTag,
      numericRefinements: Object.fromEntries(
        Object.entries(st.numericRefinements || {}).map(([attr, ops]) => [
          attr,
          Object.fromEntries(Object.entries(ops || {}).map(([op, vals]) => [op, vals]))
        ])
      ),
      facetsRefinements: st.facetsRefinements || {},
      disjunctiveFacetsRefinements: st.disjunctiveFacetsRefinements || {}
    };
    this.debugTarget.value = JSON.stringify(dbg, null, 2);
  }

  _logFacetDiagnostics(helper, requestedAttrs) {
    try {
      const lr = helper?.lastResults;
      const raw0 = lr?._rawResults?.[0] || {};
      const dist = raw0.facets || raw0.facetDistribution || lr?.facets || {};
      const gotKeys = Object.keys(dist || {});
      const missing = (requestedAttrs || []).filter(a => !gotKeys.includes(a));
      const changed = gotKeys.join('|') !== this._lastFacetKeys.join('|');
      this._lastFacetKeys = gotKeys;

      logFacets('requested=%o, got=%o (checks=%o)', requestedAttrs, gotKeys, this._facetRenderChecks);
      hard('facet diag', { requested: requestedAttrs, got: gotKeys });

      if (missing.length && this._facetRenderChecks < 2) return;

      if (missing.length) {
        if (!this._facetWarnedOnce || changed) {
          const msg = `Missing facet distributions for: ${missing.join(', ')}. Ensure filterableAttributes are set.`;
          logFacets('WARNING: %s', msg);
          hard('WARNING', msg);
          this._facetWarnedOnce = true;
        }
      } else if (gotKeys.length) {
        if (this._facetWarnedOnce && changed) hard('facet diagnostics recovered with keys', gotKeys);
        this._facetWarnedOnce = false;
      }
    } catch (e) {
      logFacets('facet diagnostics failed → %o', e?.message || e);
      hard('facet diag error', e?.message || e);
    }
  }
}
