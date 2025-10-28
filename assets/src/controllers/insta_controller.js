// -----------------------------------------------------------------------------
// File: assets/src/controllers/insta_controller.js
// Version: MEILI-INSTA v3.9
// Note: leveled logging via loglevel; enable via getLogger('insta').setLevel('trace')
// -----------------------------------------------------------------------------
import { Controller } from '@hotwired/stimulus';
import * as StimAttrs from 'stimulus-attributes';
import instantsearch from 'instantsearch.js';
import { instantMeiliSearch } from '@meilisearch/instant-meilisearch';
import Twig from 'twig';

import '../debug/fetch_tap.js';
import '../debug/fetch_rewrite_single.js';
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

// ---- leveled logger
import log from 'loglevel';
const LOG = log.getLogger('insta'); // control with getLogger('insta').setLevel('trace')

let Routing = null;
try {
  const module = await import('fos-routing');
  Routing = module.default;
  const RoutingDataModule = await import('/js/fos_js_routes.js');
  const RoutingData = RoutingDataModule.default;
  Routing.setData(RoutingData);
} catch (e) {
  LOG.debug('Routing not available', e?.message ?? e);
}

installTwigAPI({ Routing, StimAttrs });

const DBG = (...a) => LOG.debug(...a); // keep a shorthand

export default class extends Controller {
  static targets = [
    'searchBox',
    'hits',
    'reset',
    'pagination',
    'refinementList',
    'stats',
    'semanticSlider',
    'semanticOutput',
    'sortBy',
    'debug',
    'submit',
    'scoreThreshold',
    'scoreMultiplier',
    'notice'
  ];

  static values = {
    serverUrl: String,
    serverApiKey: String,
    indexName: String,
    embedderName: String,
    semanticEnabled: { type: Boolean, default: true },
    semanticRatio: Number,
    templateUrl: String,
    userLocale: { type: String, default: 'en' },
    q: { type: String, default: '' },
    hitClass: { type: String, default: 'grid-3' },
    globalsJson: { type: String, default: '{}' },
    iconsJson: { type: String, default: '{}' },
    sortingJson: { type: String, default: '[]' },
    configJson: { type: String, default: '{}' },
    scoreThreshold: { type: Number, default: 0 },
    searchAsYouType: { type: Boolean, default: true }
  };

  initialize() {
    LOG.info('initialize()', {
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

    this._globalFacetCounts = {};
    this.search = null;

    this._pendingQuery = (this.qValue || '').trim();

    // response telemetry
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

    LOG.debug('initialize:config', {
      sorting: this.sorting,
      config: this.config
    });
  }

  // -----------------------------------------------------------------------------
  async connect() {
    LOG.info('connect()');
    await this._loadTemplate();

    const isSemantic = this._isSemantic();
    LOG.debug('connect:isSemantic', isSemantic);

    if (isSemantic) {
      const eff = this._effectiveThreshold();
      if (!(eff > 0)) {
        LOG.info('threshold: seeding default 0.01 (×1)');
        this._setThresholdDecimal(0.01);
      }
    }

    if (isSemantic) {
      const percent = this.hasSemanticSliderTarget ? Number(this.semanticSliderTarget.value) || 30 : 30;
      const ratio = Math.max(0, Math.min(100, percent)) / 100;
      this.semanticRatioValue = ratio;

      this._meiliOptions.meiliSearchParams.hybrid = {
        embedder: this.embedderNameValue,
        semanticRatio: ratio
      };
      LOG.debug('hybrid:init', this._meiliOptions.meiliSearchParams.hybrid);
    }

    await this._startSearch();
  }

  disconnect() {
    LOG.info('disconnect()');
    try { this.search?.dispose(); } catch (e) { LOG.warn('dispose error', e); }
    this.search = null;
  }

  async _loadTemplate() {
    if (!this.templateUrlValue) return;
    LOG.info('loading Twig template', this.templateUrlValue);
    const res = await fetch(this.templateUrlValue);
    if (!res.ok) throw new Error(`Template HTTP ${res.status}: ${res.statusText}`);
    const ct = res.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await res.json() : await res.text();
    this.template = Twig.twig({ data });
    LOG.debug('template loaded');
  }

  // -----------------------------------------------------------------------------
  _createClient() {
    LOG.info('_createClient()');
    const { searchClient } = instantMeiliSearch(
      this.serverUrlValue,
      this.serverApiKeyValue,
      this._meiliOptions
    );

    const writeDebug = (obj) => {
      if (!this.hasDebugTarget) return;
      try { this.debugTarget.value = JSON.stringify(obj, null, 2); }
      catch { this.debugTarget.value = '[debug serialization error]'; }
    };

    return {
      ...searchClient,
      search: (requests, ...rest) => {
        const reqs = Array.isArray(requests) ? requests : [requests];
        const shouldHybrid = this._isSemantic();
        const ratio = (typeof this.semanticRatioValue === 'number') ? this.semanticRatioValue : 0.30;
        const thr = this._effectiveThreshold();

        const patched = reqs.map(r => {
          const copy = { ...r };
          copy.params = { ...(r.params || {}) };

          if (copy.params.query && !copy.q) copy.q = copy.params.query;

          copy.showRankingScore = true;
          copy.showRankingScoreDetails = true;
          copy.params.showRankingScore = true;
          copy.params.showRankingScoreDetails = true;

          if (shouldHybrid) {
            const h = { embedder: this.embedderNameValue, semanticRatio: ratio };
            copy.hybrid = h;
            copy.params.hybrid = h;
          } else {
            delete copy.hybrid;
            delete copy.params.hybrid;
          }

          copy.rankingScoreThreshold = thr;
          copy.params.rankingScoreThreshold = thr;

          copy.highlightPreTag = '__ais-highlight__';
          copy.highlightPostTag = '__ais-highlight__';
          copy.params.highlightPreTag = '__ais-highlight__';
          copy.params.highlightPostTag = '__ais-highlight__';

          return copy;
        });

        // Console + textarea: exact body we send to /multi-search
        LOG.trace('→ /multi-search', { queries: patched });
        writeDebug({ queries: patched });

        return searchClient.search(patched, ...rest).then(resp => {
          try {
            const r0 = resp?.results?.[0] || null;
            this._lastServerEstimated = r0?.estimatedTotalHits ?? null;
            this._lastServerPageCount = Array.isArray(r0?.hits) ? r0.hits.length : null;

            if (Array.isArray(r0?.hits)) {
              const scores = r0.hits
                .map(h => Number(h?._rankingScore))
                .filter(Number.isFinite)
                .sort((a,b)=>a-b);
              this._lastPageScoreMin = scores[0] ?? null;
              this._lastPageScoreMax = scores[scores.length-1] ?? null;
            } else {
              this._lastPageScoreMin = this._lastPageScoreMax = null;
            }

            LOG.trace('← /multi-search', {
              estimatedTotalHits: this._lastServerEstimated,
              pageHits: this._lastServerPageCount,
              scoreMin: this._lastPageScoreMin,
              scoreMax: this._lastPageScoreMax
            });
          } catch (e) {
            LOG.warn('response parse error', e);
          }
          return resp;
        }).catch(err => {
          LOG.error('/multi-search failed', err);
          throw err;
        });
      }
    };
  }

  // -----------------------------------------------------------------------------
  async _startSearch(initialUiState = null) {
    LOG.info('_startSearch()');
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

    await this._prefetchGlobalFacets().catch(e => LOG.debug('prefetch facets skipped', e?.message ?? e));

    const semantic = this._isSemantic();
    const searchAsYouType = this.hasSearchAsYouTypeValue
      ? !!this.searchAsYouTypeValue
      : !semantic;
    LOG.info('typing mode', { searchAsYouType, semantic });

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
        cssClasses: {
          item: this.hitClassValue,
          loadMore: 'btn btn-primary',
          disabledLoadMore: 'btn btn-secondary disabled'
        },
        transformItems: (items) => {
          const thr = this._effectiveThreshold();
          if (thr <= 0) {
            this._lastClientKeptCount = items.length;
            LOG.trace('transformItems pass-through', { count: items.length, thr });
            return items;
          }
          const kept = [];
          for (const it of items) {
            const s = Number(it?._rankingScore);
            if (Number.isFinite(s) && s >= thr) kept.push(it);
          }
          this._lastClientKeptCount = kept.length;
          LOG.trace('transformItems filtered', { input: items.length, kept: kept.length, thr });
          return kept;
        },
        templates: {
          item: (hit) => {
            const body = this.template
              ? this.template.render({
                  hit,
                  _config: this.config,
                  _score: hit._rankingScore,
                  _scoreDetails: hit._rankingScoreDetails,
                  icons: this.icons,
                  globals: this.globals,
                  hints: (this.config?.hints || {}),
                  view: (this.config?.view || {})
                })
              : `<pre>${escapeHtml(JSON.stringify(hit, null, 2))}</pre>`;
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
    LOG.info('instantsearch started');

    is.on('render', () => {
      const thr = this._effectiveThreshold();
      const total = this._lastServerEstimated ?? null;
      const kept = this._lastClientKeptCount ?? null;
      if (this.hasNoticeTarget && total != null) {
        const msg = thr > 0
          ? `Showing <strong>${kept ?? 0}</strong> (min ×${Math.round(thr * 100)}) of <strong>${total}</strong> found.`
          : `Found <strong>${total}</strong>.`;
        this.noticeTarget.innerHTML = `<div class="small text-muted">${msg}</div>`;
      }
      LOG.trace('render HUD', { thr, total, kept });
    });

    const wireManualSubmit = () => {
      const input = this.searchBoxTarget?.querySelector?.('input[type="search"], input[type="text"]');
      if (!input) return;

      const updatePending = () => {
        this._pendingQuery = input.value ?? '';
        const trimmed = this._pendingQuery.trim();

        if (!searchAsYouType) {
          this._setSubmitEnabled(trimmed.length > 0);
        }

        if (trimmed.length === 0) {
          try {
            const state = this.search.getUiState() || {};
            const key = this.indexNameValue;
            this.search.setUiState({
              ...state,
              [key]: { ...(state[key] || {}), query: undefined, page: 1 }
            });
            LOG.debug('cleared → landing state');
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
      LOG.info('manual-submit mode wired');
    } else {
      this._setSubmitEnabled(false);
      if (this.hasSubmitTarget) this.submitTarget.style.display = 'none';
      wireManualSubmit();
      LOG.info('search-as-you-type mode wired');
    }
  }

  _setSubmitEnabled(on) {
    if (!this.hasSubmitTarget) return;
    this.submitTarget.disabled = !on;
    this.submitTarget.classList.toggle('disabled', !on);
    LOG.trace('submit enabled?', on);
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
      LOG.info('manual submit', { q });
    } catch {
      try { this.search?.refresh(); } catch {}
    }
  }

  setMinScore(event) {
    let raw = (event?.currentTarget?.value ?? '').trim();
    let v = Number(raw);
    if (!Number.isFinite(v) || v < 0) v = 0;
    if (v > 1) v = 1;
    this._setThresholdDecimal(v);
    LOG.info('threshold set (decimal)', v);
    try { this.search?.refresh(); } catch {}
  }

  setMinScoreMultiplier(event) {
    const raw = (event?.currentTarget?.value ?? '').trim();
    let m = Number(raw);
    if (!Number.isFinite(m) || m < 0) m = 0;
    if (m > 100) m = 100;
    const v = m / 100;
    this._setThresholdDecimal(v);
    LOG.info('threshold set (multiplier)', m, '=>', v);
    try { this.search?.refresh(); } catch {}
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
      LOG.debug('prefetch facets ok', Object.keys(this._globalFacetCounts));
    } catch (e) {
      LOG.debug('prefetch facets failed', e?.message ?? e);
      throw e;
    }
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
    if (!this._isSemantic()) return;
    const el = this.hasSemanticSliderTarget ? this.semanticSliderTarget : (event?.currentTarget ?? null);
    const percent = Number(el?.value ?? 0) || 0;
    const clamped = Math.max(0, Math.min(100, percent));
    const ratio = clamped / 100;
    if (this.semanticRatioValue !== ratio) {
      this.semanticRatioValue = ratio;
      if (this.hasSemanticOutputTarget) this.semanticOutputTarget.textContent = `${Math.round(clamped)}%`;
      LOG.info('semantic ratio ->', ratio);
      try { this.search?.refresh(); } catch {}
    }
  }

  _isSemantic() {
    const on = this.hasEmbedderNameValue && this.semanticEnabledValue;
    LOG.trace('_isSemantic()', on);
    return on;
  }

  _effectiveThreshold() {
    if (this.hasScoreMultiplierTarget) {
      const mult = Number(String(this.scoreMultiplierTarget.value ?? '').trim());
      if (Number.isFinite(mult) && mult > 0) {
        const v = Math.min(1, mult / 100);
        LOG.trace('_effectiveThreshold via multiplier', mult, '=>', v);
        return v;
      }
    }
    const dec = Number(this.scoreThresholdValue);
    if (Number.isFinite(dec) && dec > 0) {
      LOG.trace('_effectiveThreshold via decimal', dec);
      return dec;
    }
    const v = this._isSemantic() ? 0.01 : 0;
    LOG.trace('_effectiveThreshold default', v);
    return v;
  }

  _setThresholdDecimal(decimalValue) {
    const v = Math.max(0, Math.min(1, Number(decimalValue) || 0));
    this.scoreThresholdValue = v;
    if (this.hasScoreThresholdTarget) this.scoreThresholdTarget.value = String(v);
    if (this.hasScoreMultiplierTarget) this.scoreMultiplierTarget.value = String(Math.round(v * 100));
    LOG.debug('threshold synced', { decimal: v, multiplier: Math.round(v*100) });
  }
}
