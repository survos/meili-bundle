import { Controller } from '@hotwired/stimulus';
import * as StimAttrs from 'stimulus-attributes';
import instantsearch from 'instantsearch.js';
import { instantMeiliSearch } from '@meilisearch/instant-meilisearch';
import Twig from 'twig'; // AssetMapper maps to browser ESM

import {
  searchBox, infiniteHits, stats, pagination,
  currentRefinements, clearRefinements, hitsPerPage, sortBy, configure
} from 'instantsearch.js/es/widgets';

import { installTwigAPI } from './insta_twig.js';
import { safeParse, stripProtocol, escapeHtml, normalizeConfig } from './insta_helpers.js';
import { mountFacetFromNode } from './insta_facets.js';

// Optional Routing for path() helper (ok as top-level await in ESM)
let Routing = null;
try {
  const module = await import('fos-routing');
  Routing = module.default;
  const RoutingDataModule = await import('/js/fos_js_routes.js');
  const RoutingData = RoutingDataModule.default;
  Routing.setData(RoutingData);
} catch { /* optional */ }

installTwigAPI({ Routing, StimAttrs });

const DBG = (...a) => console.debug('[insta]', ...a);

export default class extends Controller {
  static targets = [
    'searchBox','hits','reset','pagination','refinementList','stats',
    'semanticSlider','semanticOutput','sortBy'
  ];

  static values = {
    serverUrl: String,
    serverApiKey: String,
    indexName: String,
    embedderName: String,
    semanticRatio: Number, // 0.0–1.0
    templateUrl: String,
    userLocale: { type: String, default: 'en' },
    hitClass: { type: String, default: 'grid-3' },
    globalsJson: { type: String, default: '{}' },
    iconsJson: { type: String, default: '{}' },
    sortingJson: { type: String, default: '[]' },
    configJson: { type: String, default: '{}' }
  };

  initialize() {
    this.globals = safeParse(this.globalsJsonValue, {});
    if (!this.globals._sc_modal) this.globals._sc_modal = '@survos/meili-bundle/json';

    this.icons   = safeParse(this.iconsJsonValue, {});
    window.__survosIconsMap = this.icons || {};

    this.sorting = safeParse(this.sortingJsonValue, []);
    this.config  = normalizeConfig( safeParse(this.configJsonValue, {}) );

    this._globalFacetCounts = {};
    this.search = null;

    // Options snapshot used when creating the meili client
    this._meiliOptions = {
      meiliSearchParams: {
        keepZeroFacets: false,
        showRankingScore: true,
        showRankingScoreDetails: true
        // 'hybrid' injected when embedder present
      }
    };

    // Debounce for typing only (avoid vectorizing partial words)
    this._timer = null;
    this._debounce = (fn, ms = 350) => (...args) => {
      clearTimeout(this._timer);
      this._timer = setTimeout(() => fn(...args), ms);
    };
    this._debouncedQueryHook = this._debounce((q, hook) => hook(q), 350);

    this._rebuilding = false;
  }

  async connect() {
    await this._loadTemplate();

    // Seed hybrid BEFORE creating the client (if embedder configured)
    if (this.hasEmbedderNameValue && !this._meiliOptions.meiliSearchParams.hybrid) {
      const percent = this.hasSemanticSliderTarget ? Number(this.semanticSliderTarget.value) || 30 : 30;
      const ratio = Math.max(0, Math.min(100, percent)) / 100;
      this.semanticRatioValue = ratio;
      this._meiliOptions.meiliSearchParams.hybrid = {
        embedder: this.embedderNameValue,
        semanticRatio: ratio
      };
      DBG('hybrid:init', this._meiliOptions.meiliSearchParams.hybrid);
    }

    await this._startSearch();
  }

  disconnect() {
    try { this.search?.dispose(); } catch {}
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

  _createClient() {
    const { searchClient } = instantMeiliSearch(
      this.serverUrlValue,
      this.serverApiKeyValue,
      this._meiliOptions
    );

    // Wrap to log outgoing queries and responses
    return {
      ...searchClient,
      search: (requests, ...rest) => {
        try {
          const q = Array.isArray(requests) ? requests.map((r) => ({
            indexUid: r.indexName || r.indexUid,
            params: r.params || {},
          })) : requests;
          DBG('multi-search:REQUEST', JSON.parse(JSON.stringify(q)));
          DBG('client:meiliSearchParams', JSON.parse(JSON.stringify(this._meiliOptions.meiliSearchParams)));
        } catch {}
        return searchClient.search(requests, ...rest).then((resp) => {
          try {
            const out = (resp?.results || []).map((r) => ({
              indexUid: r.indexUid || r.index,
              hits: r.hits?.length ?? 0,
              sample: (r.hits || []).slice(0, 3).map(h => ({
                id: h.id ?? h.objectID,
                title: h.title ?? h.name ?? h._formatted?.title ?? null
              }))
            }));
            DBG('multi-search:RESPONSE', out);
          } catch {}
          return resp;
        });
      }
    };
  }

  async _startSearch() {
    const is = instantsearch({
      indexName: this.indexNameValue,
      searchClient: this._createClient(),
      routing: this.config?.instantsearch?.routing ?? true,
      insights: this.config?.instantsearch?.insights ?? false
    });
    this.search = is;
    window.search = is; // handy in DevTools

    await this._prefetchGlobalFacets().catch(() => {});

    const widgets = [
      searchBox({
        container: this.searchBoxTarget,
        placeholder: `${this.indexNameValue} on ${stripProtocol(this.serverUrlValue)}`,
        queryHook: this._debouncedQueryHook
      }),
      ...(this.hasStatsTarget ? [ stats({ container: this.statsTarget }) ] : []),
      ...(this.hasResetTarget ? [ clearRefinements({
        container: this.resetTarget,
        clearsQuery: true,
        templates: { reset: 'Reset all filters' },
        cssClasses: { button: 'btn btn-link p-0', disabledButton: 'text-muted' }
      }) ] : []),
      hitsPerPage({
        container: document.createElement('div'),
        items: [
          { value: 10, label: '10 / page' },
          { value: 20, label: '20 / page', default: true },
          { value: 50, label: '50 / page' }
        ]
      }),
      configure({ showRankingScore: true, hitsPerPage: 20 }),
      ...(this.hasHitsTarget ? [ infiniteHits({
        container: this.hitsTarget,
        cssClasses: {
          item: this.hitClassValue,
          loadMore: 'btn btn-primary',
          disabledLoadMore: 'btn btn-secondary disabled'
        },
        templates: {
          item: (hit) => {
            try {
              return this.template
                ? this.template.render({
                    hit,
                    icons: this.icons,
                    globals: this.globals,
                    hints: (this.config?.hints || {}),
                    view: (this.config?.view || {})
                  })
                : `<pre>${escapeHtml(JSON.stringify(hit, null, 2))}</pre>`;
            } catch (e) {
              return `<pre>${escapeHtml(e.message)}</pre>`;
            }
          }
        }
      }) ] : []),
      ...(this.hasPaginationTarget ? [ pagination({ container: this.paginationTarget }) ] : [])
    ];

    // SortBy next to the slider
    if (this.hasSortByTarget) {
      widgets.push(sortBy({
        container: this.sortByTarget,
        items: this.sorting
      }));
    }

    is.addWidgets(widgets);

    // Sidebar facets
    const nodes = this.refinementListTarget?.querySelectorAll?.('[data-attribute][data-widget]') || [];
    nodes.forEach((el) => mountFacetFromNode(this, is, el));

    // Initialize slider output
    if (this.hasSemanticSliderTarget && this.hasSemanticOutputTarget) {
      const p = Number(this.semanticSliderTarget.value) || 30;
      this.semanticOutputTarget.textContent = `${Math.round(p)}%`;
    }

    is.start();
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

  /**
   * Slider action — **change** only (wire in Twig: data-action="change->...#semantic").
   * Rebuilds the client with updated meiliSearchParams.hybrid and restarts search.
   */
  async semantic(event) {
    console.log(event);
    if (!this.hasEmbedderNameValue) return;

    const el = this.hasSemanticSliderTarget ? this.semanticSliderTarget : (event?.currentTarget ?? null);
    const percent = Number(el?.value ?? 0) || 0;
    const clamped = Math.max(0, Math.min(100, percent));
    const ratio = clamped / 100;

    if (this.semanticRatioValue === ratio) return;

    this.semanticRatioValue = ratio;
    if (this.hasSemanticOutputTarget) {
      this.semanticOutputTarget.textContent = `${Math.round(clamped)}%`;
    }

    // Update and log the actual params we will use to build the client
    this._meiliOptions.meiliSearchParams.hybrid = {
      embedder: this.embedderNameValue,
      semanticRatio: ratio
    };
    DBG('hybrid:change', this._meiliOptions.meiliSearchParams.hybrid);

    // Full rebuild (dispose + start new) to ensure the adapter snapshots new params
    if (this._rebuilding) return;
    this._rebuilding = true;
    try {
      try { this.search?.dispose(); } catch {}
      this.search = null;
      await this._startSearch();
    } finally {
      this._rebuilding = false;
    }
  }
}
