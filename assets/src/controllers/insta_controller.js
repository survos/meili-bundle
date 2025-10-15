import { Controller } from '@hotwired/stimulus';
import * as StimAttrs from 'stimulus-attributes';
import instantsearch from 'instantsearch.js';
import { instantMeiliSearch } from '@meilisearch/instant-meilisearch';
import Twig from 'twig';

import {
  searchBox, infiniteHits, stats, pagination,
  currentRefinements, clearRefinements, hitsPerPage, sortBy, configure
} from 'instantsearch.js/es/widgets';

// our modules
import { installTwigAPI } from './insta_twig.js';
import {
  safeParse, stripProtocol, escapeHtml, normalizeConfig
} from './insta_helpers.js';
import { mountFacetFromNode } from './insta_facets.js';

// optional Routing for path() helper
let Routing = null;
try {
  const module = await import('fos-routing');
  Routing = module.default;
  const RoutingDataModule = await import('/js/fos_js_routes.js');
  const RoutingData = RoutingDataModule.default;
  Routing.setData(RoutingData);
} catch { /* optional */ }

// install Twig helpers once
installTwigAPI({ Routing, StimAttrs });

export default class extends Controller {
  static targets = ['searchBox','hits','sort','reset','pagination','refinementList','stats'];
  static values = {
    serverUrl: String,
    serverApiKey: String,
    indexName: String,
    embedderName: String,
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

    this.regionNames   = new Intl.DisplayNames([this.userLocaleValue], { type: 'region' });
    this.languageNames = new Intl.DisplayNames([this.userLocaleValue], { type: 'language' });

    this._facetWidgets = new Map();
    this._globalFacetCounts = {}; // full-index baseline counts
    this._is = null;
  }

  async connect() {
    await this._loadTemplate();
    await this._startSearch();
  }

  async _loadTemplate() {
    if (!this.templateUrlValue) return;
    const res = await fetch(this.templateUrlValue);
    if (!res.ok) throw new Error(`Template HTTP ${res.status}: ${res.statusText}`);
    const ct = res.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await res.json() : await res.text();
    this.template = Twig.twig({ data });
  }

  async _startSearch() {
    const { searchClient } = instantMeiliSearch(
      this.serverUrlValue,
      this.serverApiKeyValue,
      { meiliSearchParams: { keepZeroFacets: false, showRankingScore: true, showRankingScoreDetails: true, semantic_ratio: 0.5 } }
    );

    const is = instantsearch({
      indexName: this.indexNameValue,
      searchClient,
      routing: this.config?.instantsearch?.routing ?? true,
      insights: this.config?.instantsearch?.insights ?? false
    });
    this._is = is;
    window.search = is;

    await this._prefetchGlobalFacets().catch(() => {});

    is.addWidgets([
      searchBox({ container: this.searchBoxTarget, placeholder: `${this.indexNameValue} on ${stripProtocol(this.serverUrlValue)}` }),
      ...(this.hasStatsTarget ? [ stats({ container: this.statsTarget }) ] : []),
      ...(this.hasSortTarget ? [ currentRefinements({ container: this.sortTarget }) ] : []),
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
      sortBy({ container: document.createElement('div'), items: this.sorting }),
      configure({ showRankingScore: true, hitsPerPage: 20 }),
      ...(this.hasHitsTarget ? [ (
        await import('instantsearch.js/es/widgets')).infiniteHits({
        container: this.hitsTarget,
        cssClasses: { item: this.hitClassValue, loadMore: 'btn btn-primary', disabledLoadMore: 'btn btn-secondary disabled' },
        templates: {
          item: (hit) => {
            try {
              return this.template
                ? this.template.render({ hit, icons: this.icons, globals: this.globals, hints: (this.config?.hints || {}), view: (this.config?.view || {}) })
                : `<pre>${escapeHtml(JSON.stringify(hit, null, 2))}</pre>`;
            } catch (e) { return `<pre>${escapeHtml(e.message)}</pre>`; }
          }
        }
      }) ] : []),
      ...(this.hasPaginationTarget ? [ pagination({ container: this.paginationTarget }) ] : [])
    ]);

    // Mount only real facet nodes
    const nodes = this.refinementListTarget?.querySelectorAll?.('[data-attribute][data-widget]') || [];
    nodes.forEach((el) => mountFacetFromNode(this, is, el));

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
}
