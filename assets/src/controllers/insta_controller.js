// -----------------------------------------------------------------------------
// File: assets/src/controllers/insta_controller.js
// Version: MEILI-INSTA v4.7-sort-fix
//  - Custom multi-search client that injects `hybrid` (embedder + semanticRatio).
//  - Fix: translate Algolia-style facetFilters / numericFilters -> Meilisearch `filter`.
//  - Fix: translate Algolia replica-style indexName (for sortBy) -> Meili `indexUid` + `sort`.
//  - Keep: facets array normalization + fallback from config/schema.
//  - Keep: highlight conversion, hybrid/threshold handling, debug hooks.
// -----------------------------------------------------------------------------

import { Controller } from '@hotwired/stimulus';
import * as StimAttrs from 'stimulus-attributes';
import instantsearch from 'instantsearch.js';
import { instantMeiliSearch } from '@meilisearch/instant-meilisearch';

// import * as bootstrap from 'bootstrap';

// AI: NEVER ELIMINATE THIS, it is very important
import Twig from 'twig';

import '../debug/fetch_tap.js';
import { createDebug } from '../debug/debug.js';
import {
  searchBox,
  infiniteHits,
  stats,
  pagination,
  clearRefinements,
  hitsPerPage,
  sortBy,
  configure
} from 'instantsearch.js/es/widgets';

import { installTwigAPI } from './insta_twig.js';
import { safeParse, stripProtocol, escapeHtml, normalizeConfig } from './insta_helpers.js';
import { mountFacetFromNode } from './insta_facets.js';

// Optional routes
let Routing = null;
try {
  const module = await import('fos-routing');
  Routing = module.default;
  const RoutingDataModule = await import('/js/fos_js_routes.js?abc');
  const RoutingData = RoutingDataModule.default;
  Routing.setData(RoutingData);
} catch { /* ignore */ }
installTwigAPI({ Routing, StimAttrs });

// Debug logger (enable with: localStorage.debug = 'insta:*,wire:*,hl:*,view:*')
const logInsta = createDebug('insta:core');
const logWire  = createDebug('wire:meili');
const logHL    = createDebug('hl:convert');
const logView  = createDebug('view:render');

export default class extends Controller {
  static targets = [
    'searchBox', 'hits', 'reset', 'pagination', 'refinementList', 'stats',
    'semanticSlider', 'semanticOutput', 'sortBy', 'debug', 'submit',
    'scoreThreshold', 'scoreMultiplier', 'notice', 'facetsSidebar'
  ];

  static values = {
    serverUrl: String,
    serverApiKey: String,
    indexName: String,

    // Hybrid
    embedderName: String,
    semanticEnabled: { type: Boolean, default: true },
    semanticRatio: Number,

    // UI
    templateUrl: String,
    userLocale: { type: String, default: 'en' },
    q: { type: String, default: '' },
    hitClass: { type: String, default: 'grid-3' },
    globalsJson: { type: String, default: '{}' },
    iconsJson: { type: String, default: '{}' },
    sortingJson: { type: String, default: '[]' },
    configJson: { type: String, default: '{}' },

    // Server-side score threshold
    scoreThreshold: { type: Number, default: 0 },

    // Typing behavior
    searchAsYouType: { type: Boolean, default: true }
  };

  initialize() {
    logInsta('initialize %o', {
      index: this.indexNameValue,
      url: this.serverUrlValue,
      embedder: this.embedderNameValue,
      semanticEnabled: this.semanticEnabledValue
    });

    this.globals = safeParse(this.globalsJsonValue, {});
    if (!this.globals._sc_modal) this.globals._sc_modal = '@survos/meili-bundle/json';

    this.icons  = safeParse(this.iconsJsonValue, {});
    window.__survosIconsMap = this.icons || {};

    this.sorting = safeParse(this.sortingJsonValue, []);
    this.config  = normalizeConfig(safeParse(this.configJsonValue, {}));

    // HUD
    this._globalFacetCounts = {};
    this.search = null;
    this._pendingQuery = (this.qValue || '').trim();

    this._lastServerEstimated = null;
    this._lastServerPageCount = null;
    this._lastPageScoreMin = null;
    this._lastPageScoreMax = null;
    this._lastClientKeptCount = null;

    this._meiliOptions = {
      meiliSearchParams: {
        keepZeroFacets: false,
        showRankingScore: true,
        showRankingScoreDetails: true
      }
    };

    // Initialize Bootstrap Dropdown
    document.body.addEventListener('click', function(event) {
      const toggle = event.target.closest('[data-bs-toggle="dropdown"]');
      if (!toggle) {
          return;
      }
      event.preventDefault();
      const instance = bootstrap.Dropdown.getOrCreateInstance(toggle);
      instance.toggle();
    });
  }

  async connect() {
    await this._loadTemplate();

    if (this._isSemantic()) {
      if (!(this._effectiveThreshold() > 0)) this._setThresholdDecimal(0.01); // Tac default
      const percent = this.hasSemanticSliderTarget ? Number(this.semanticSliderTarget.value) || 30 : 30;
      this.semanticRatioValue = Math.max(0, Math.min(100, percent)) / 100;
    }

    await this._startSearch();
  }

  disconnect() {
    try { this.search?.dispose(); } catch { /* ignore */ }
    this.search = null;
  }

  async _loadTemplate() {
    if (!this.templateUrlValue) return;
    const res = await fetch(this.templateUrlValue);
    if (!res.ok) throw new Error(`Template HTTP ${res.status}: ${res.statusText}`);
    const ct = res.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await res.json() : await res.text();
    this.template = Twig.twig({ data });
  }

  // ---------------------------------------------------------------------------
  // Helpers: convert Algolia-esque filters -> Meilisearch filter string
  // ---------------------------------------------------------------------------
  _facetTermToMeili(term) {
    // Supports "-attr:value" -> attr != "value"
    let neg = false;
    let t = String(term);
    if (t.startsWith('-')) { neg = true; t = t.slice(1); }
    const idx = t.indexOf(':');
    if (idx < 0) return null;
    const attr = t.slice(0, idx).trim();
    const val  = t.slice(idx + 1).trim();
    if (!attr) return null;
    const op = neg ? '!=' : '=';
    return `${attr} ${op} ${JSON.stringify(val)}`;
  }

  _buildMeiliFilterFromParams(p) {
    // Accepts Algolia-like:
    //  - facetFilters: ["director:Kubrick", ["genres:Comedy","genres:Drama"], "-keywords:crime"]
    //  - numericFilters: ["releaseYear >= 2000", ["runtime < 120","voteCount >= 100"]]
    const andParts = [];

    const { facetFilters, numericFilters, filters } = p || {};

    if (Array.isArray(facetFilters) && facetFilters.length) {
      const ff = facetFilters.map(group => {
        if (Array.isArray(group)) {
          const orParts = group
            .map(t => this._facetTermToMeili(t))
            .filter(Boolean);
          return orParts.length ? `(${orParts.join(' OR ')})` : null;
        } else {
          const one = this._facetTermToMeili(group);
          return one || null;
        }
      }).filter(Boolean);
      if (ff.length) andParts.push(ff.join(' AND '));
    }

    if (Array.isArray(numericFilters) && numericFilters.length) {
      const nf = numericFilters.map(group => {
        if (Array.isArray(group)) {
          const orParts = group.map(String).map(s => s.trim()).filter(Boolean);
          return orParts.length ? `(${orParts.join(' OR ')})` : null;
        } else {
          const one = String(group).trim();
          return one || null;
        }
      }).filter(Boolean);
      if (nf.length) andParts.push(nf.join(' AND '));
    }

    // If `filters` (Algolia boolean syntax string) is provided by caller, prefer it last
    if (typeof filters === 'string' && filters.trim().length) {
      andParts.push(filters.trim());
    }

    if (!andParts.length) return null;
    return andParts.join(' AND ');
  }

  // ---------------------------------------------------------------------------
  // Custom client (multi-search) with robust _formatted → _highlightResult
  // ---------------------------------------------------------------------------
  _createClient() {
    // Sentinels prevent Meili from touching real tags
    const SENTINEL_PRE  = '__ais-highlight__';
    const SENTINEL_POST = '__/ais-highlight__';

    const { searchClient } = instantMeiliSearch(
      this.serverUrlValue,
      this.serverApiKeyValue,
      this._meiliOptions
    );

    const toAlgoliaResult = (meiliResult, queryShape) => {
      const q = queryShape || {};
      const limit =
        Number.isFinite(+q.limit) ? +q.limit
        : Number.isFinite(+meiliResult?.limit) ? +meiliResult.limit
        : 20;

      const MARK_OPEN = '<mark class="ais-Highlight">';
      const MARK_CLOSE = '</mark>';

      // decode & swap on a primitive
      const decodeAndSwap = (s) => {
        const el = document.createElement('textarea');
        el.innerHTML = String(s);
        const decoded = el.value;
        const swapped = decoded
          .replaceAll(SENTINEL_PRE, MARK_OPEN)
          .replaceAll(SENTINEL_POST, MARK_CLOSE);
        return swapped;
      };

      // normalize any _formatted value into Algolia-style { value } (or array)
      const toHighlightEntry = (v) => {
        if (v == null) return null;

        if (
          typeof v === 'string' ||
          typeof v === 'number' ||
          typeof v === 'boolean'
        ) {
          return { value: decodeAndSwap(v) };
        }

        if (Array.isArray(v)) {
          const arr = v
            .map(entry => {
              if (entry == null) return null;

              if (
                typeof entry === 'string' ||
                typeof entry === 'number' ||
                typeof entry === 'boolean'
              ) {
                return { value: decodeAndSwap(entry) };
              }

              try {
                return { value: decodeAndSwap(JSON.stringify(entry)) };
              } catch {
                return null;
              }
            })
            .filter(Boolean);

          return arr.length ? arr : null;
        }

        try {
          return { value: decodeAndSwap(JSON.stringify(v)) };
        } catch {
          return null;
        }
      };

      // debug: peek at raw _formatted from Meili (first 2 hits)
      const sampleFormatted = (meiliResult?.hits || [])
        .slice(0, 2)
        .map(h => h?._formatted ?? null);
      logHL('raw _formatted (first 2): %o', sampleFormatted);

      const hits = (meiliResult?.hits || []).map((hit, idx) => {
        if (hit && hit._formatted && typeof hit._formatted === 'object') {
          const h = {};
          for (const [k, v] of Object.entries(hit._formatted)) {
            const entry = toHighlightEntry(v);
            if (entry) h[k] = entry;
          }
          hit._highlightResult = h;

          const anyVal = h?.title?.value ?? h?.name?.value ?? h?.headline?.value ?? null;
          if (idx < 2) {
            logHL(
              'post-conversion contains <mark>? %o  escaped &lt;mark? %o  value=%o',
              typeof anyVal === 'string' && anyVal.includes('<mark'),
              typeof anyVal === 'string' && anyVal.includes('&lt;mark'),
              anyVal
            );
          }
          delete hit._formatted;
        } else if (idx < 2) {
          logHL('no _formatted on hit %d', idx);
        }

        if (hit && hit._rankingScore != null) {
          hit._rankingScore = Number(hit._rankingScore);
        }
        return hit;
      });

      const nbHits = Number.isFinite(+meiliResult?.estimatedTotalHits)
        ? +meiliResult.estimatedTotalHits
        : hits.length;

      // Map Meili facetStats -> Algolia facets_stats (for RangeSlider, etc.)
      const facets = meiliResult?.facetDistribution ?? {};
      const rawStats = meiliResult?.facetStats || meiliResult?.facet_stats || null;
      const facets_stats = {};

      if (rawStats && typeof rawStats === 'object') {
        for (const [attr, stats] of Object.entries(rawStats)) {
          if (!stats || typeof stats !== 'object') continue;
          const min = Number(stats.min);
          const max = Number(stats.max);
          if (Number.isFinite(min) && Number.isFinite(max)) {
            facets_stats[attr] = { min, max };
          }
        }
      }

      const base = {
        hits,
        nbHits,
        nbPages: Math.max(1, Math.ceil(nbHits / limit)),
        hitsPerPage: limit,
        processingTimeMS: Number.isFinite(+meiliResult?.processingTimeMs)
          ? +meiliResult.processingTimeMs
          : 0,
        query: meiliResult?.query ?? q.q ?? '',
        facets
      };

      if (Object.keys(facets_stats).length) {
        base.facets_stats = facets_stats;
      }

      return base;
    };

    const buildQueries = (requests, shouldHybrid, ratio, threshold) => {
      return requests.map(r => {
        const p  = r.params || {};
        const hp = (p.hitsPerPage ?? p.limit ?? 20) | 0;
        const pg = Number.isFinite(+p.page) ? (+p.page) : 0;
        const off = p.hasOwnProperty('offset') ? (+p.offset || 0) : (pg * (hp || 20));

        // -------------------------------------------------------------------
        // Sort handling
        // -------------------------------------------------------------------
        // InstantSearch's sortBy widget *renames* the index instead of passing
        // a `sort` param (Algolia replica pattern), e.g.:
        //   indexName = "dtdemo_jeopardy:monthIndex:asc"
        //
        // Meilisearch expects:
        //   indexUid = "dtdemo_jeopardy"
        //   sort     = ["monthIndex:asc"]
        //
        // We detect and normalize that here.
        const baseIndexName =
          r.indexName ?? r.indexUid ?? r.index ?? this.indexNameValue;

        let indexUid = baseIndexName;
        let sort = p.sort;

        if (!sort && typeof baseIndexName === 'string') {
          const parts = baseIndexName.split(':');
          // Expect at least "index:field:dir"
          if (parts.length >= 3) {
            indexUid = parts.shift(); // real index name
            const attr = parts.shift();
            const dir  = parts.shift();
            if (attr && dir) {
              sort = [`${attr}:${dir}`];
            }
          }
        }

        const out = {
          indexUid,
          q:        r.q ?? p.q ?? p.query ?? '',
          limit:    Math.max(1, hp || 20),
          offset:   Math.max(0, off),

          // Highlights + retrieval of _formatted
          attributesToRetrieve: Array.isArray(p.attributesToRetrieve) && p.attributesToRetrieve.length
            ? p.attributesToRetrieve
            : ['*', '_formatted'],
          attributesToHighlight: Array.isArray(p.attributesToHighlight) && p.attributesToHighlight.length
            ? p.attributesToHighlight
            : ['*'],
          highlightPreTag:  SENTINEL_PRE,
          highlightPostTag: SENTINEL_POST,

          // Scores
          showRankingScore: true,
          showRankingScoreDetails: true,
        };

        // Normalize params that Meilisearch expects in specific shapes
        if (sort) {
          out.sort = Array.isArray(sort) ? sort : [sort];
        }
        if (p.distinct)             out.distinct = p.distinct;
        if (p.matchingStrategy)     out.matchingStrategy = p.matchingStrategy;

        if (p.attributesToSearchOn) {
          out.attributesToSearchOn = Array.isArray(p.attributesToSearchOn)
            ? p.attributesToSearchOn
            : [p.attributesToSearchOn];
        }

        // Build `filter` from facetFilters / numericFilters / filters when no direct `filter` present
        if (p.filter) {
          out.filter = p.filter;
        } else {
          const built = this._buildMeiliFilterFromParams(p);
          if (built) out.filter = built;
        }

        // --- FACETS HANDLING ---
        // Prefer what InstantSearch provides; otherwise fallback from config/schema
        let facetsParam = p.facets;
        if (facetsParam == null) {
          const schemaFacets = this.config?.schema?.filterableAttributes || [];
          const configuredFacets = Array.isArray(this.config?.facets)
            ? this.config.facets.map(f => f.attribute)
            : Object.keys(this.config?.facets || {});
          const dedup = Array.from(new Set([...(schemaFacets || []), ...(configuredFacets || [])]))
            .filter(Boolean);
          facetsParam = dedup.length ? dedup : null;
          if (!p.facets && facetsParam) {
            logWire('facets fallback from config/schema → %o', facetsParam);
          }
        }
        if (facetsParam != null) {
          out.facets = Array.isArray(facetsParam) ? facetsParam : [String(facetsParam)];
        }

        // Optional: coerce single strings to arrays if caller set them oddly
        if (p.attributesToRetrieve && !Array.isArray(p.attributesToRetrieve)) {
          out.attributesToRetrieve = [String(p.attributesToRetrieve)];
        }
        if (p.attributesToHighlight && !Array.isArray(p.attributesToHighlight)) {
          out.attributesToHighlight = [String(p.attributesToHighlight)];
        }

        if (shouldHybrid) {
          out.hybrid = { embedder: this.embedderNameValue, semanticRatio: ratio };
          if (threshold > 0) out.rankingScoreThreshold = threshold;
        }

        logWire('buildQueries → %o', {
          indexUid: out.indexUid, q: out.q,
          attributesToRetrieve: out.attributesToRetrieve,
          attributesToHighlight: out.attributesToHighlight,
          highlightPreTag: out.highlightPreTag,
          highlightPostTag: out.highlightPostTag,
          showRankingScore: out.showRankingScore,
          rankingScoreThreshold: out.rankingScoreThreshold,
          hybrid: out.hybrid,
          facets: out.facets,
          filter: out.filter,
          sort: out.sort
        });

        return out;
      });
    };

    const base = (this.serverUrlValue || '').replace(/\/+$/,'');
    const multiUrl = `${base}/multi-search`;

    return {
      ...searchClient,

      search: async (requests) => {
        const reqs = Array.isArray(requests) ? requests : [requests];

        const shouldHybrid = this._isSemantic();
        const ratio = (typeof this.semanticRatioValue === 'number') ? this.semanticRatioValue : 0.30;
        const thr   = this._effectiveThreshold();

        const queries = buildQueries(reqs, shouldHybrid, ratio, thr);

        const headers = { 'Content-Type': 'application/json' };
        if (this.serverApiKeyValue) headers.Authorization = `Bearer ${this.serverApiKeyValue}`;

        const body = { queries };
        window.__meiliLastMulti = { url: multiUrl, body };
        logWire('WIRE → %s %o', multiUrl, body);

        let raw, ok = true, status = 0, errText = '';
        try {
          const res = await fetch(multiUrl, {
            method: 'POST',
            headers,
            body: JSON.stringify(body)
          });
          status = res.status;
          ok     = res.ok;
          raw    = ok ? await res.json() : null;
          if (!ok) { try { errText = await res.text(); } catch {} }
        } catch (e) {
          ok = false; errText = String(e?.message ?? e);
        }

        if (ok) {
          logWire('WIRE ← %o', raw);
        } else {
          logWire('WIRE ← ERROR status=%o err=%o', status, errText);
        }

        let multiJson = null;
        if (ok && raw) {
          if (Array.isArray(raw?.results)) multiJson = raw;
          else if (Array.isArray(raw?.hits)) multiJson = { results: [ raw ] };
          else multiJson = { results: [ raw ] };
        }

        if (!ok || !multiJson || !Array.isArray(multiJson.results)) {
          logWire('search fail: status=%o err=%o', status, errText);
          const empty = { hits: [], nbHits: 0, page: 0, nbPages: 1, hitsPerPage: 20, processingTimeMS: 0, query: '' };
          this._lastServerEstimated = 0;
          this._lastServerPageCount = 0;
          this._lastPageScoreMin = null;
          this._lastPageScoreMax = null;
          return { results: [ empty ] };
        }

        const algResults = multiJson.results.map((r, i) => toAlgoliaResult(r, queries[i]));

        try {
          const r0 = algResults[0] || {};
          const hits = Array.isArray(r0.hits) ? r0.hits : [];
          const scores = hits.map(h => Number(h?._rankingScore))
            .filter(Number.isFinite)
            .sort((a,b)=>a-b);
          this._lastServerEstimated = r0.nbHits ?? null;
          this._lastServerPageCount = hits.length;
          this._lastPageScoreMin = scores[0] ?? null;
          this._lastPageScoreMax = scores[scores.length - 1] ?? null;
        } catch {
          this._lastServerEstimated = null;
          this._lastServerPageCount = null;
          this._lastPageScoreMin = null;
          this._lastPageScoreMax = null;
        }

        return { results: algResults };
      }
    };
  }

  // ---------------------------------------------------------------------------
  async _startSearch(initialUiState = null) {
    const ui = initialUiState ?? {
      [this.indexNameValue]: {
        query: (this.qValue || '').trim() || undefined
      }
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

    await this._prefetchGlobalFacets().catch(() => {});

    const semantic = this._isSemantic();
    const searchAsYouType = this.hasSearchAsYouTypeValue
      ? !!this.searchAsYouTypeValue
      : !semantic;

    const widgets = [
      searchBox({
        container: this.searchBoxTarget,
        searchAsYouType,
        placeholder: `${this.indexNameValue} on ${stripProtocol(this.serverUrlValue)} ${this.qValue}`,
        autofocus: false
      }),

      ...(this.hasStatsTarget ? [stats({ container: this.statsTarget })] : []),

      ...(this.hasResetTarget ? [clearRefinements({
        container: this.resetTarget,
        clearsQuery: false,
        templates: { reset: 'Clear refinements' },
        escapeHTML: false,
        cssClasses: { button: 'btn btn-link p-0', disabledButton: 'text-muted' }
      })] : []),

      hitsPerPage({
        container: document.createElement('div'),
        items: [
          { value: 10, label: '10 / page' },
          { value: 20, label: '20 / page', default: true },
          { value: 50, label: '50 / page' }
        ]
      }),

      configure({
        showRankingScore: true,
        hitsPerPage: 20,
        rankingScoreThreshold: this._effectiveThreshold()
      }),

      ...(this.hasHitsTarget ? [infiniteHits({
        container: this.hitsTarget,
        escapeHTML: false,
        cssClasses: {
          item: this.hitClassValue,
          loadMore: 'btn btn-primary',
          disabledLoadMore: 'btn btn-secondary disabled'
        },
        transformItems: (items) => {
          this._lastClientKeptCount = items.length;
          return items;
        },
        templates: {
          item: (hit) => {
            const ctx = {
              hit,
              _config: this.config,
              _score: hit._rankingScore,
              _scoreDetails: hit._rankingScoreDetails,
              _isSemantic: this._isSemantic(),
              icons: this.icons,
              _sc_modal: this.globals._sc_modal,
              globals: this.globals,
              hints: (this.config?.hints || {}),
              view: (this.config?.view || {})
            };
            const body = this.template ? this.template.render(ctx)
              : `<pre>${escapeHtml(JSON.stringify(hit, null, 2))}</pre>`;

            logView('twig body (first 500 chars) → %o', body?.slice?.(0, 500) ?? body);

            return `<div class="insta-hit" data-score="${Number(hit?._rankingScore) || ''}">${body}</div>`;
          },
          empty: () => `<div class="text-muted">No results.</div>`
        }
      })] : []),

      ...(this.hasPaginationTarget ? [pagination({ container: this.paginationTarget })] : [])
    ];

    if (this.hasSortByTarget) {
      widgets.push(sortBy({ container: this.sortByTarget, items: this.sorting }));
    }

    const nodes = this.refinementListTarget?.querySelectorAll?.('[data-attribute][data-widget]') || [];
    nodes.forEach(el => mountFacetFromNode(this, is, el));

    is.addWidgets(widgets);
    is.start();

    is.on('render', () => {
      try {
        const first = this.hitsTarget?.querySelector?.('.insta-hit');
        if (first) {
          logView('DOM .insta-hit innerHTML (first 500 chars) → %o', first.innerHTML.slice(0, 500));
        }
      } catch {}
      const thr = this._effectiveThreshold();
      const total = this._lastServerEstimated ?? null;
      const kept = this._lastClientKeptCount ?? null;
      if (this.hasNoticeTarget && total != null) {
        const msg = thr > 0
          ? `Showing <strong>${kept ?? 0}</strong> (min ×${Math.round(thr * 100)}) of <strong>${total}</strong> found.`
          : `Found <strong>${total}</strong>.`;
        this.noticeTarget.innerHTML = `<div class="small text-muted">${msg}</div>`;
      }
    });

    const wireManualSubmit = () => {
      const input = this.searchBoxTarget?.querySelector?.('input[type="search"], input[type="text"]');
      if (!input) return;

      const updatePending = () => {
        this._pendingQuery = input.value ?? '';
        const trimmed = this._pendingQuery.trim();

        if (!searchAsYouType) this._setSubmitEnabled(trimmed.length > 0);

        if (trimmed.length === 0) {
          try {
            const state = this.search.getUiState() || {};
            const key = this.indexNameValue;
            this.search.setUiState({
              ...state,
              [key]: { ...(state[key] || {}), query: undefined, page: 1 }
            });
          } catch {
            try { this.search?.refresh(); } catch {}
          }
        }
      };

      input.addEventListener('input', updatePending);
      input.addEventListener('change', updatePending);
      updatePending();
    };

    if (!searchAsYouType) {
      wireManualSubmit();
      if (this.hasSubmitTarget) this.submitTarget.style.display = '';
    } else {
      this._setSubmitEnabled(false);
      if (this.hasSubmitTarget) this.submitTarget.style.display = 'none';
      wireManualSubmit();
    }
  }

  _setSubmitEnabled(on) {
    if (!this.hasSubmitTarget) return;
    this.submitTarget.disabled = !on;
    this.submitTarget.classList.toggle('disabled', !on);
  }

  submit(event) {
    event?.preventDefault?.();
    let q = this._pendingQuery;
    try {
      const input = this.searchBoxTarget.querySelector('input[type="search"], input[type="text"]');
      if (input) q = input.value;
    } catch {}
    q = (q || '').trim();
    if (!q) return;
    try {
      const state = this.search.getUiState() || {};
      const key = this.indexNameValue;
      this.search.setUiState({ ...state, [key]: { ...(state[key] || {}), query: q, page: 1 } });
    } catch {
      try { this.search?.refresh(); } catch {}
    }
  }

  async _prefetchGlobalFacets() {
    try {
      const { Meilisearch } = await import('meilisearch');
      const client = new Meilisearch({ host: this.serverUrlValue, apiKey: this.serverApiKeyValue || undefined });
      const index = client.index(this.indexNameValue);

      const schemaFacets = this.config?.schema?.filterableAttributes || [];
      const configuredFacets = Array.isArray(this.config?.facets)
        ? this.config.facets.map(f => f.attribute)
        : Object.keys(this.config?.facets || {});
      const facets = schemaFacets.length ? schemaFacets : configuredFacets;
      if (!facets.length) return;

      const res = await index.search('', { facets, hitsPerPage: 0, limit: 0 });
      this._globalFacetCounts = res.facetDistribution || {};
    } catch { /* ignore */ }
  }

  _currentQuery() {
    try {
      const ui = this.search?.getUiState?.() || {};
      const key = this.indexNameValue;
      const q = ui?.[key]?.query;
      if (typeof q === 'string') return q;
    } catch {}
    const input = this.searchBoxTarget?.querySelector?.('input[type="search"], input[type="text"]');
    if (input?.value) return input.value.trim();
    return (this.qValue || '').trim();
  }

  async semantic(event) {
    event?.preventDefault?.();
    /*if (!this._isSemantic()) return;*/
    const el = this.hasSemanticSliderTarget ? this.semanticSliderTarget : (event?.currentTarget ?? null);
    const percent = Number(el?.value ?? 0) || 0;
    const clamped = Math.max(0, Math.min(100, percent));
    const ratio = clamped / 100;
    if (this.semanticRatioValue !== ratio) {
      this.semanticRatioValue = ratio;
      if (this.hasSemanticOutputTarget) this.semanticOutputTarget.textContent = `${Math.round(clamped)}%`;
      try { this.search?.refresh(); } catch {}
    }
  }

  _isSemantic() {
    return this.hasEmbedderNameValue && this.semanticEnabledValue;
  }

  _effectiveThreshold() {
    if (this.hasScoreMultiplierTarget) {
      const mult = Number(String(this.scoreMultiplierTarget.value ?? '').trim());
      if (Number.isFinite(mult) && mult > 0) return Math.min(1, mult / 100);
    }
    const dec = Number(this.scoreThresholdValue);
    if (Number.isFinite(dec) && dec > 0) return dec;
    return this._isSemantic() ? 0.01 : 0;
  }

  _setThresholdDecimal(decimalValue) {
    const v = Math.max(0, Math.min(1, Number(decimalValue) || 0));
    this.scoreThresholdValue = v;
    if (this.hasScoreThresholdTarget) this.scoreThresholdTarget.value = String(v);
    if (this.hasScoreMultiplierTarget) this.scoreMultiplierTarget.value = String(Math.round(v * 100));
  }

  setMinScoreMultiplier(event) {
    event?.preventDefault?.();

    // Read numeric "multiplier" from input (0–100, representing % × 0.01)
    const raw = Number(String(event?.currentTarget?.value ?? '').trim());
    const mult = Number.isFinite(raw) ? raw : 0;

    // Convert to decimal threshold [0,1]
    const decimal = Math.max(0, Math.min(1, mult / 100));

    // Update internal + input fields via existing helper
    this._setThresholdDecimal(decimal);

    // Re-run search with new threshold
    try {
      this.search?.refresh();
    } catch {
      /* ignore */
    }
  }

  showFacetsSidebar() {
    document.querySelectorAll('.offcanvas-backdrop').forEach(backdrop => backdrop.remove());
    bootstrap.Offcanvas.getOrCreateInstance(this.facetsSidebarTarget).show();
  }

  hideFacetsSidebar() {
    bootstrap.Offcanvas.getOrCreateInstance(this.facetsSidebarTarget).hide();
    document.querySelectorAll('.offcanvas-backdrop').forEach(backdrop => backdrop.remove());
  }

}
