// -----------------------------------------------------------------------------
// File: assets/src/controllers/insta_controller.js
// Version: MEILI-INSTA v3.5
// Unique Note: [insta_controller.js::v3.5::manual-submit+multiplier-threshold::2025-10-17]
// -----------------------------------------------------------------------------
//
// CHANGELOG (v3.5):
// - Keeps your StimAttrs + Twig helpers and the exact `import Twig from 'twig'`.
// - Search bar is manual-submit (no search-as-you-type). Facets remain instant.
// - Threshold input now supports a *multiplier* control (e.g. "3" => 0.03).
//   • New action: setMinScoreMultiplier() — divides by 100, clamps [0,1], refreshes.
//   • We still support the old decimal-box handler setMinScore() for compatibility.
// - Notice shows both decimal and multiplier, e.g. "(score ≥ 0.09, ×9)".
// - Client-side cutoff via transformItems + DOM guard; server threshold still sent.
// - CANDIDATE FOR REVIEW: mirroring flags both flat & nested; could refactor later.
// -----------------------------------------------------------------------------

import { Controller } from '@hotwired/stimulus';
import * as StimAttrs from 'stimulus-attributes';
import instantsearch from 'instantsearch.js';
import { instantMeiliSearch } from '@meilisearch/instant-meilisearch';
import Twig from 'twig';

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

// NOTE(CANDIDATE FOR REFACTORING): If the browser ever pulls the Node build of Twig
// ("require is not defined"), ensure AssetMapper resolves the browser ESM build. We
// keep your `import Twig from 'twig'` exactly as-is per your requirement.

let Routing = null;
try {
  const module = await import('fos-routing');
  Routing = module.default;
  const RoutingDataModule = await import('/js/fos_js_routes.js');
  const RoutingData = RoutingDataModule.default;
  Routing.setData(RoutingData);
} catch {}

installTwigAPI({ Routing, StimAttrs });

const DBG = (...a) => console.debug('[insta]', ...a);

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
    'scoreThreshold',   // legacy decimal box (optional)
    'scoreMultiplier',  // NEW: multiplier box (e.g. 3 => 0.03)
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
    scoreThreshold: { type: Number, default: 0 } // effective decimal [0,1]
  };

  initialize() {
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
  }

  async connect() {
    await this._loadTemplate();

    if (this.hasEmbedderNameValue && this.semanticEnabledValue) {
      const percent = this.hasSemanticSliderTarget ? Number(this.semanticSliderTarget.value) || 30 : 30;
      const ratio = Math.max(0, Math.min(100, percent)) / 100;
      this.semanticRatioValue = ratio;

      this._meiliOptions.meiliSearchParams.hybrid = {
        embedder: this.embedderNameValue,
        semanticRatio: ratio
      };
      DBG('hybrid:init (seed)', this._meiliOptions.meiliSearchParams.hybrid);
    }

    await this._startSearch();
  }

  disconnect() { try { this.search?.dispose(); } catch {}; this.search = null; }

  async _loadTemplate() {
    if (!this.templateUrlValue) return;
    const res = await fetch(this.templateUrlValue);
    if (!res.ok) throw new Error(`Template HTTP ${res.status}: ${res.statusText}`);
    const ct = res.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await res.json() : await res.text();
    this.template = Twig.twig({ data });
  }

  _createClient() {
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

        const shouldHybrid = this.hasEmbedderNameValue && this.semanticEnabledValue;
        const ratio = (typeof this.semanticRatioValue === 'number') ? this.semanticRatioValue : 0.30;
        const thr = Number(this.scoreThresholdValue) || 0;

        const patched = reqs.map(r => {
          const copy = { ...r };
          copy.params = { ...(r.params || {}) };

          if (copy.params.query && !copy.q) copy.q = copy.params.query;

          // Always request scores
          copy.showRankingScore = true;
          copy.showRankingScoreDetails = true;
          copy.params.showRankingScore = true;
          copy.params.showRankingScoreDetails = true;

          // Hybrid both shapes
          if (shouldHybrid) {
            const h = { embedder: this.embedderNameValue, semanticRatio: ratio };
            copy.hybrid = h;
            copy.params.hybrid = h;
          } else {
            delete copy.hybrid;
            delete copy.params.hybrid;
          }

          // Server-side threshold (if >0)
          if (thr > 0) {
            copy.rankingScoreThreshold = thr;
            copy.params.rankingScoreThreshold = thr;
          } else {
            delete copy.rankingScoreThreshold;
            delete copy.params.rankingScoreThreshold;
          }

          // Normalize highlight tags
          copy.highlightPreTag = '__ais-highlight__';
          copy.highlightPostTag = '__ais-highlight__';
          copy.params.highlightPreTag = '__ais-highlight__';
          copy.params.highlightPostTag = '__ais-highlight__';

          return copy;
        });

        writeDebug({ queries: patched });
        DBG('multi-search:REQUEST', patched.map(r => ({
          indexUid: r.indexName || r.indexUid,
          q: r.q ?? r.params?.query ?? '',
          ratio: (r.hybrid ?? r.params?.hybrid)?.semanticRatio ?? null,
          threshold: thr,
          thresholdMultiplier: Math.round(thr * 100)
        })));

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

            const out = (resp?.results || []).map(r => ({
              indexUid: r.indexUid || r.index,
              hits: r.hits?.length ?? 0,
              sample: (r.hits || []).slice(0, 3).map(h => ({
                id: h.id ?? h.objectID,
                title: h.title ?? h.name ?? h._formatted?.title ?? null,
                _rankingScore: h._rankingScore
              }))
            }));
            DBG('multi-search:RESPONSE', out, {
              estimatedTotalHits: this._lastServerEstimated,
              pageHits: this._lastServerPageCount,
              pageScoreMin: this._lastPageScoreMin,
              pageScoreMax: this._lastPageScoreMax,
              threshold: thr,
              thresholdMultiplier: Math.round(thr * 100)
            });
          } catch {}
          return resp;
        });
      }
    };
  }

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

    const widgets = [
      searchBox({
        container: this.searchBoxTarget,
        searchAsYouType: false,
        placeholder: `${this.indexNameValue} on ${stripProtocol(this.serverUrlValue)} ${this.qValue}`,
        autofocus: false
      }),

      ...(this.hasStatsTarget ? [stats({ container: this.statsTarget })] : []),

      ...(this.hasResetTarget ? [clearRefinements({
        container: this.resetTarget,
        clearsQuery: false,
        templates: { reset: 'Reset all filters' },
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

      configure({ showRankingScore: true, hitsPerPage: 20 }),

      ...(this.hasHitsTarget ? [infiniteHits({
        container: this.hitsTarget,
        cssClasses: {
          item: this.hitClassValue,
          loadMore: 'btn btn-primary',
          disabledLoadMore: 'btn btn-secondary disabled'
        },
        transformItems: (items) => {
          const thr = Number(this.scoreThresholdValue) || 0;
          if (thr <= 0) { this._lastClientKeptCount = items.length; return items; }
          const kept = [];
          const dropped = [];
          for (const it of items) {
            const s = Number(it?._rankingScore);
            if (Number.isFinite(s) && s >= thr) kept.push(it); else dropped.push(it);
          }
          this._lastClientKeptCount = kept.length;
          if (items.length > 0) {
            DBG('filter:score', {
              threshold: thr,
              thresholdMultiplier: Math.round(thr * 100),
              received: items.length,
              kept: kept.length,
              dropped: dropped.length,
              keptMin: kept.length ? Math.min(...kept.map(i => Number(i._rankingScore))) : null,
              keptMax: kept.length ? Math.max(...kept.map(i => Number(i._rankingScore))) : null,
              dropMax: dropped.length ? Math.max(...dropped.map(i => Number(i._rankingScore) || 0)) : null
            });
          }
          return kept;
        },
        templates: {
          // RESTORED item template (do not remove)
          item: (hit) => {
            const score = (typeof hit._rankingScore === 'number') ? hit._rankingScore : null;
            const scoreBadge = `<span class="badge text-bg-info float-end">score: ${score !== null ? score.toFixed(6) : '—'}</span>`;
            const body = this.template
                ? this.template.render({
                  hit: hit,
                  _config: this.config,
                  _score: hit._rankingScore,
                  _scoreDetails: hit._rankingScoreDetails,
                  icons: this.icons,
                  globals: this.globals,
                  hints: (this.config?.hints || {}),
                  view: (this.config?.view || {})
                })
                : `<pre>${escapeHtml(JSON.stringify(hit, null, 2))}</pre>`;

            // Wrap with a marker div so we can sanity-filter after render
            return `<div class="insta-hit" data-score="${score ?? ''}">${scoreBadge}${body}</div>`;
          },
          empty: () => {
            const thr = Number(this.scoreThresholdValue) || 0;
            const total = this._lastServerEstimated ?? '—';
            if (thr > 0 && (this._lastClientKeptCount === 0)) {
              return `
                <div class="alert alert-warning">
                  Found <strong>${total}</strong> results, but none on this page met your minimum score
                  <strong>≥ ${thr}</strong> (×${Math.round(thr * 100)}). Lower the Min score or adjust the query.
                </div>`;
            }
            return `<div class="text-muted">No results.</div>`;
          }
        }
      })] : []),

      ...(this.hasPaginationTarget ? [pagination({ container: this.paginationTarget })] : [])
    ];

    if (this.hasSortByTarget) {
      widgets.push(sortBy({ container: this.sortByTarget, items: this.sorting }));
    }

    // Sidebar facets (instant)
    const nodes = this.refinementListTarget?.querySelectorAll?.('[data-attribute][data-widget]') || [];
    nodes.forEach(el => mountFacetFromNode(this, is, el));

    is.addWidgets(widgets);
    is.start();

    // Update "notice" + DOM guard each render
    is.on('render', () => {
      const thr = Number(this.scoreThresholdValue) || 0;
      const total = this._lastServerEstimated ?? null;
      const kept = this._lastClientKeptCount ?? null;
      if (this.hasNoticeTarget && total != null) {
        const msg = thr > 0
          ? `Showing <strong>${kept ?? 0}</strong> (score ≥ ${thr} <span class="text-nowrap">×${Math.round(thr * 100)}</span>) of <strong>${total}</strong> found.`
          : `Found <strong>${total}</strong>.`;
        this.noticeTarget.innerHTML = `<div class="small text-muted">${msg}</div>`;
      }

      // DOM guard (belt & suspenders)
      try {
        if (thr > 0 && this.hasHitsTarget) {
          const cards = this.hitsTarget.querySelectorAll('.insta-hit[data-score]');
          cards.forEach(el => {
            const s = Number(el.getAttribute('data-score'));
            el.style.display = (Number.isFinite(s) && s >= thr) ? '' : 'none';
          });
        }
      } catch {}
    });

    // Track input to enable Search button; Enter submits via widget's form
    try {
      const input = this.searchBoxTarget.querySelector('input[type="search"], input[type="text"]');
      if (input) {
        const updatePending = () => {
          this._pendingQuery = input.value;
          this._setSubmitEnabled(!!this._pendingQuery.trim());
        };
        input.addEventListener('input', updatePending);
        input.addEventListener('change', updatePending);
        updatePending();
      }
    } catch {}
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
      this.search.setUiState({ ...state, [key]: { ...(state[key] || {}), query: q } });
    } catch { try { this.search?.refresh(); } catch {} }
  }

  // Legacy decimal textbox: clamp to [0,1], normalize >1 as percent
  setMinScore(event) {
    let raw = (event?.currentTarget?.value ?? '').trim();
    let v = Number(raw);
    if (Number.isFinite(v) && v > 1) {
      v = v / 100;
      if (this.hasScoreThresholdTarget) this.scoreThresholdTarget.value = String(v);
      console.info('[insta] normalized min score (decimal box) to', v);
    }
    if (!Number.isFinite(v) || v < 0) v = 0;
    if (v > 1) v = 1;
    this.scoreThresholdValue = v;
    try { this.search?.refresh(); } catch {}
  }

  // NEW: multiplier textbox (e.g. "3" => 0.03)
  setMinScoreMultiplier(event) {
    const raw = (event?.currentTarget?.value ?? '').trim();
    let m = Number(raw);
    if (!Number.isFinite(m) || m < 0) m = 0;
    // CANDIDATE FOR REVIEW: upper bound. Using 100 => max 1.00
    if (m > 100) m = 100;
    const v = m / 100;
    this.scoreThresholdValue = v;

    // If a legacy decimal box is present, keep them in sync
    if (this.hasScoreThresholdTarget) {
      this.scoreThresholdTarget.value = String(v);
    }
    // reflect clamped value back to multiplier box (e.g., if >100)
    if (this.hasScoreMultiplierTarget) {
      this.scoreMultiplierTarget.value = String(m);
    }

    DBG('threshold:multiplier', { multiplier: m, decimal: v });
    try { this.search?.refresh(); } catch {}
  }

  async _prefetchGlobalFacets() {
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
    if (!this.hasEmbedderNameValue) return;
    const el = this.hasSemanticSliderTarget ? this.semanticSliderTarget : (event?.currentTarget ?? null);
    const percent = Number(el?.value ?? 0) || 0;
    const clamped = Math.max(0, Math.min(100, percent));
    const ratio = clamped / 100;
    if (this.semanticRatioValue !== ratio) {
      this.semanticRatioValue = ratio;
      if (this.hasSemanticOutputTarget) this.semanticOutputTarget.textContent = `${Math.round(clamped)}%`;
      DBG('hybrid:ratio', this.semanticRatioValue);
      try { this.search?.refresh(); } catch {}
    }
  }
}
