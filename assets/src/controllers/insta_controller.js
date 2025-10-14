import { Controller } from '@hotwired/stimulus';

// Hard requirement: stimulus-attributes
import * as StimAttrs from 'stimulus-attributes';

// Optional: FOSJsRouting (path() helper is safe if missing)
let Routing = null;
try {
  const module = await import('fos-routing');
  Routing = module.default;
  const RoutingDataModule = await import('/js/fos_js_routes.js');
  const RoutingData = RoutingDataModule.default;
  Routing.setData(RoutingData);
} catch { /* ignore; path() helper logs if used */ }

// InstantSearch
import instantsearch from 'instantsearch.js';
import { instantMeiliSearch } from '@meilisearch/instant-meilisearch';
import {
  searchBox, infiniteHits, stats, pagination,
  currentRefinements, clearRefinements, refinementList,
  toggleRefinement, rangeSlider, numericMenu,
  ratingMenu, menu, hitsPerPage, sortBy, configure
} from 'instantsearch.js/es/widgets';

// Twig.js
import Twig from 'twig';
import 'flag-icons/css/flag-icons.min.css';

/* ---------------- Twig helpers ---------------- */
Twig.extend(function (TwigApi) {
  // path()
  TwigApi._function.extend('path', (route, routeParams = {}) => {
    if (!Routing) {
      console.warn('[insta] FOSJsRouting missing. Install: npm i fos-routing and expose /js/fos_js_routes.js');
      return `#install-fos-routing(${String(route)})`;
    }
    if (routeParams && typeof routeParams === 'object' && '_keys' in routeParams) {
      delete routeParams._keys;
    }
    return Routing.generate(route, routeParams);
  });

  // stimulus-attributes (required)
  TwigApi._function.extend('stimulus_controller', (name, values = {}, classes = {}, outlets = {}) =>
    StimAttrs.stimulus_controller(name, values, classes, outlets)
  );
  TwigApi._function.extend('stimulus_target', (name, target = null) =>
    StimAttrs.stimulus_target(name, target)
  );
  TwigApi._function.extend('stimulus_action', (name, action, eventName = null, params = {}) =>
    StimAttrs.stimulus_action(name, action, eventName, params)
  );

  // ux_icon(name, attrs?)
  TwigApi._function.extend('ux_icon', (name, attrs = {}) => {
    if (!name) return '';
    const map = (window.__survosIconsMap || {});
    const svg = map[name];
    if (!svg) {
      console.warn('[insta] ux_icon("' + name + '") not found in iconsJson.');
      return '';
    }
    if (attrs && typeof attrs === 'object' && attrs.class) {
      return `<span class="${String(attrs.class)}">${svg}</span>`;
    }
    return svg;
  });
});

/* ---------------- utils & formatters ---------------- */
const safeParse = (s, fallback) => { try { return JSON.parse(s) } catch { return fallback } };
const stripProtocol = (u) => (u || '').replace(/(^\w+:|^)\/\//, '');
const escapeHtml = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const DEFAULT_NON_SEARCHABLE = new Set(['gender', 'marking', 'house', 'currentParty', 'countries', 'locale']);

const FORMATTERS = {
  monthIndex(i) {
    const year = Math.floor(i / 12), month = i % 12;
    return new Date(year, month, 1).toLocaleString('default', { year: 'numeric', month: 'short' });
  },
  int(v) { return Math.round(Number(v) || 0); },
  usd(v) { return '$' + new Intl.NumberFormat().format(Number(v) || 0); },
};

function coerceWidgetOptions(widgetName, opts) {
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

// Accept facets as array or map; default widget = RefinementList
function normalizeConfig(raw) {
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

/* ---------------- Stimulus Controller ---------------- */
export default class extends Controller {
  static targets = [
    'searchBox','hits','template','sort','reset','pagination','refinementList','stats'
  ];
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
    this.icons   = safeParse(this.iconsJsonValue, {});
    window.__survosIconsMap = this.icons || {};
    this.sorting = safeParse(this.sortingJsonValue, []);
    this.config  = normalizeConfig( safeParse(this.configJsonValue, {}) );

    this.regionNames   = new Intl.DisplayNames([this.userLocaleValue], { type: 'region' });
    this.languageNames = new Intl.DisplayNames([this.userLocaleValue], { type: 'language' });

    this._facetWidgets = new Map();
    this._is = null;

    if (this.globals?.debug) console.debug('[insta] config', this.config);
  }

  async connect() {
    await this._loadTemplate();
    this._startSearch();
  }

  async _loadTemplate() {
    if (!this.templateUrlValue) return;
    const res = await fetch(this.templateUrlValue);
    if (!res.ok) throw new Error(`Template HTTP ${res.status}: ${res.statusText}`);
    const ct = res.headers.get('content-type') || '';
    const data = ct.includes('application/json') ? await res.json() : await res.text();
    this.template = Twig.twig({ data });
  }

  _startSearch() {
    const { searchClient } = instantMeiliSearch(
      this.serverUrlValue,
      this.serverApiKeyValue,
      { meiliSearchParams: { keepZeroFacets: false, showRankingScore: true, showRankingScoreDetails: true, semantic_ratio: 0.5 } }
    );
    window.searchClient = searchClient;

    const is = instantsearch({
      indexName: this.indexNameValue,
      searchClient,
      routing: this.config?.instantsearch?.routing ?? true,
      insights: this.config?.instantsearch?.insights ?? false
    });
    this._is = is;
    window.search = is;

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
      ...(this.hasHitsTarget ? [ infiniteHits({
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

// Only the real mount nodes (must have both attribute + widget)
    const nodes = this.refinementListTarget?.querySelectorAll?.('[data-attribute][data-widget]') || [];
    nodes.forEach((el) => this._mountFacetFromNode(is, el));

    is.start();
  }

  /**
   * Mounts the facet widget into the pre-rendered node.
   * Required: data-attribute
   * Optional: data-widget, data-sort-mode ('count'|'alpha'|'name'), data-searchable, data-limit, data-show-more-limit, data-lookup (JSON)
   * RangeSlider extras: data-step, data-min, data-max, data-pips, data-tooltips (JSON: {"format":"monthIndex"})
   * UI controls (optional): [data-sort-control], [data-collapse-control]
   */
  _mountFacetFromNode(is, el) {
    const ds = el.dataset || {};
    const attribute = ds.attribute;
    if (!attribute) {
      console.warn('[insta] facet node without data-attribute', el);
      return;
    }

    const cfgMap = Array.isArray(this.config.facets)
      ? Object.fromEntries(this.config.facets.map(f => [f.attribute, f]))
      : (this.config.facets || {});
    const cfg = cfgMap[attribute] || {};
    const widget = (ds.widget || cfg.widget || cfg.type || 'RefinementList');

    const merged = {
      attribute,
      widget,
      sortMode: ds.sortMode || cfg.sortMode, // 'count' | 'alpha' | 'name'
      searchable: ds.searchable !== undefined ? ds.searchable === 'true' : (cfg.searchable ?? undefined),
      limit: ds.limit ? Number(ds.limit) : (cfg.limit ?? undefined),
      showMoreLimit: ds.showMoreLimit ? Number(ds.showMoreLimit) : (cfg.showMoreLimit ?? undefined),
      lookup: (() => { try { return ds.lookup ? JSON.parse(ds.lookup) : (cfg.lookup || {}); } catch { return cfg.lookup || {}; }})(),
      props: cfg.props || {},
      options: cfg.options || {},
      // range extras
      step: ds.step ? Number(ds.step) : (cfg.step ?? undefined),
      min: ds.min ? Number(ds.min) : (cfg.min ?? undefined),
      max: ds.max ? Number(ds.max) : (cfg.max ?? undefined),
      pips: (ds.pips !== undefined) ? ds.pips : (cfg.pips ?? undefined),
      tooltips: (() => { try { return ds.tooltips ? JSON.parse(ds.tooltips) : (cfg.tooltips || undefined); } catch { return cfg.tooltips || undefined; }})(),
    };

    if (this.globals?.debug) console.debug('[insta] mount facet', { node: el, merged });

    // For collapse button (optional)
    const block = el.closest('[data-facet-block]');
    const collapseBtn = block ? block.querySelector('[data-collapse-control][data-attribute="' + attribute + '"]') : null;
    if (collapseBtn) {
      // Initial state …
      const body = el;
      const expanded = collapseBtn.getAttribute('aria-expanded') !== 'false';
      body.style.display = expanded ? '' : 'none';

      collapseBtn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const body = el;
        body.style.display = (body.style.display === 'none') ? '' : 'none';
        collapseBtn.setAttribute('aria-expanded', body.style.display !== 'none' ? 'true' : 'false');
      });
    }


    // Icon sort toggles: <button data-sort-toggle data-mode="count|name" data-attribute="...">
    const iconWrap = block ? block.querySelector('.facet-sort-icons[data-attribute="' + attribute + '"]') : null;
    if (iconWrap) {
      iconWrap.querySelectorAll('[data-sort-toggle]').forEach(btn => {
        btn.addEventListener('click', (ev) => {
          ev.preventDefault();
          const mode = btn.dataset.mode === 'name' ? 'alpha' : 'count';
          // update pressed states
          iconWrap.querySelectorAll('[data-sort-toggle]').forEach(b => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
          this._rebuildRefinementList(merged, el, mode);
        });
      });
    }


    switch (merged.widget) {
      case 'RangeSlider': {
        // Default to minimal chrome: no pips, no tooltips unless explicitly provided
        const optsRaw = {
          container: el,
          attribute: merged.attribute,
          pips: (merged.pips ?? false),
          step: merged.step,
          min: merged.min,
          max: merged.max,
          tooltips: (merged.tooltips === undefined ? true : merged.tooltips),
          ...(merged.props || {}),
          ...(merged.options || {}),
        };
        const opts = coerceWidgetOptions('RangeSlider', optsRaw);
        is.addWidgets([ rangeSlider(opts) ]);
        break;
      }

      case 'Toggle': {
        const opts = {
          container: el,
          attribute: merged.attribute,
          ...(merged.props || {}),
          ...(merged.options || {}),
        };
        is.addWidgets([ toggleRefinement(opts) ]);
        break;
      }

      case 'NumericMenu': {
        const opts = {
          container: el,
          attribute: merged.attribute,
          ...(merged.props || {}),
          ...(merged.options || {}),
        };
        is.addWidgets([ numericMenu(opts) ]);
        break;
      }

      case 'RatingMenu': {
        const opts = {
          container: el,
          attribute: merged.attribute,
          ...(merged.props || {}),
          ...(merged.options || {}),
        };
        is.addWidgets([ ratingMenu(opts) ]);
        break;
      }

      case 'Menu': {
        const opts = {
          container: el,
          attribute: merged.attribute,
          transformItems: (items) => this._transformItems(items, merged.attribute, merged.lookup || {}),
          ...(merged.props || {}),
          ...(merged.options || {}),
        };
        is.addWidgets([ menu(opts) ]);
        break;
      }

      case 'RefinementList':
      default: {
        const sortMode = (merged.sortMode === 'name' ? 'alpha' : merged.sortMode);
        const sortBy = sortMode === 'alpha'
          ? ['isRefined:desc','name:asc','count:desc']
          : ['isRefined:desc','count:desc','name:asc'];

        const searchable = (merged.searchable ?? !DEFAULT_NON_SEARCHABLE.has(merged.attribute));

        const opts = {
          container: el,
          attribute: merged.attribute,
          limit: merged.limit ?? 10,
          showMore: true,
          showMoreLimit: merged.showMoreLimit ?? 30,
          searchable,
          sortBy,
          transformItems: (items) => this._transformItems(items, merged.attribute, merged.lookup || {}),
          ...(merged.props || {}),
          ...(merged.options || {}),
        };

        const widgetObj = refinementList(opts);
        is.addWidgets([ widgetObj ]);
        this._facetWidgets.set(merged.attribute, widgetObj);

        // Sort control listener (select[data-sort-control])
        const sortSel = block ? block.querySelector('[data-sort-control][data-attribute="' + merged.attribute + '"]') : null;
        if (sortSel) {
          sortSel.addEventListener('change', (ev) => {
            const mode = ev.target.value === 'name' ? 'alpha' : 'count';
            this._rebuildRefinementList(merged, el, mode);
          });
        }
        break;
      }
    }
  }

  _transformItems(items, attribute, lookup) {
    if (attribute === 'locale') {
      return items.map(it => ({ ...it, highlighted: this.languageNames.of(it.value.toUpperCase()) }));
    }
    if (attribute === 'countries' || attribute === 'countryCode') {
      return items.map(it => ({ ...it, highlighted: this.regionNames.of(it.value.toUpperCase()) }));
    }
    if (!lookup || Object.keys(lookup).length === 0) return items;
    return items.map(it => ({ ...it, highlighted: lookup[it.value] || it.value }));
  }

  _rebuildRefinementList(merged, el, newSortMode) {
    const is = this._is;
    if (!is) return;

    const old = this._facetWidgets.get(merged.attribute);
    if (old) {
      try { is.removeWidgets([old]); } catch (e) { console.warn('[insta] removeWidgets', e); }
    }

    const sortBy = newSortMode === 'alpha'
      ? ['isRefined:desc','name:asc','count:desc']
      : ['isRefined:desc','count:desc','name:asc'];

    const searchable = (merged.searchable ?? !DEFAULT_NON_SEARCHABLE.has(merged.attribute));

    const widget = refinementList({
      container: el,
      attribute: merged.attribute,
      limit: merged.limit ?? 10,
      showMore: true,
      showMoreLimit: merged.showMoreLimit ?? 30,
      searchable,
      sortBy,
      transformItems: (items) => this._transformItems(items, merged.attribute, merged.lookup || {}),
      ...(merged.props || {}),
      ...(merged.options || {}),
    });

    is.addWidgets([ widget ]);
    this._facetWidgets.set(merged.attribute, widget);
  }
}
