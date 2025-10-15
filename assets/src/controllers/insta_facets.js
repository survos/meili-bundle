/**
 * Facet mounting & rebuilding (stable).
 * Depends only on helpers from insta_helpers.js and InstantSearch widgets.
 *
 * Modes:
 *  - default: data-search-mode="contains"  -> we inject a local input and do substring filtering
 *  - prefix:  data-search-mode="prefix"    -> use IS built-in facet search (SFFV)
 *
 * Always:
 *  - Respect current limit/showMore (slice to the number IS intends to render)
 *  - Backfill `highlighted` so labels never vanish
 *  - At baseline (no query/refinements and not typing in the facet), union global facetDistribution
 */
import {
  DEFAULT_NON_SEARCHABLE,
  coerceWidgetOptions,
  transformWithLookup
} from './insta_helpers.js';

import {
  refinementList, rangeSlider, toggleRefinement, numericMenu, ratingMenu, menu
} from 'instantsearch.js/es/widgets';

/** tiny util */
const toStr = (v) => String(v);

/** build an item from a [value,count] */
function buildItem(val, cnt) {
  return { label: val, value: val, highlighted: val, count: cnt, isRefined: false };
}

/**
 * Mount one facet into a pre-rendered node.
 * Expects a Stimulus controller instance `ctrl` that exposes:
 *  - ctrl.config (normalized)
 *  - ctrl._is (InstantSearch instance)
 *  - ctrl._facetWidgets (Map)
 *  - ctrl._globalFacetCounts (baseline counts)
 *  - ctrl.globals, ctrl.languageNames, ctrl.regionNames (for label transforms)
 *  - ctrl._facetLocalQuery (Map)  // created on demand for "contains" inputs
 */
export function mountFacetFromNode(ctrl, is, el) {
  const ds = el.dataset || {};
  const attribute = ds.attribute;
  if (!attribute) return;

  const cfgMap = Array.isArray(ctrl.config.facets)
    ? Object.fromEntries(ctrl.config.facets.map(f => [f.attribute, f]))
    : (ctrl.config.facets || {});
  const cfg = cfgMap[attribute] || {};
  const widget = (ds.widget || cfg.widget || cfg.type || 'RefinementList');

  // "contains" or "prefix" (default to contains)
  const searchMode = (ds.searchMode || 'contains').toLowerCase();

  const merged = {
    attribute,
    widget,
    searchMode, // NEW
    sortMode: ds.sortMode || cfg.sortMode,  // 'count' | 'alpha' | 'name'
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

  // collapse toggle: hide/show the .facet-body wrapper (not the header)
  const block = el.closest('[data-facet-block]');
  const body = block?.querySelector('.facet-body') || el;
  const collapseBtn = block ? block.querySelector('[data-collapse-control][data-attribute="' + attribute + '"]') : null;
  if (collapseBtn) {
    const expanded = collapseBtn.getAttribute('aria-expanded') !== 'false';
    body.style.display = expanded ? '' : 'none';
    collapseBtn.addEventListener('click', (ev) => {
      ev.preventDefault();
      body.style.display = (body.style.display === 'none') ? '' : 'none';
      collapseBtn.setAttribute('aria-expanded', body.style.display !== 'none' ? 'true' : 'false');
    });
  }

  // sort icon toggles
  const iconWrap = block ? block.querySelector('.facet-sort-icons[data-attribute="' + attribute + '"]') : null;
  if (iconWrap) {
    iconWrap.querySelectorAll('[data-sort-toggle]').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const mode = btn.dataset.mode === 'name' ? 'alpha' : 'count';
        iconWrap.querySelectorAll('[data-sort-toggle]').forEach(b => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
        rebuildRefinementList(ctrl, merged, el, mode);
      });
    });
  }

  switch (merged.widget) {
    case 'RangeSlider': {
      const optsRaw = {
        container: el,
        attribute: merged.attribute,
        pips: (merged.pips ?? false),
        step: merged.step,
        min: merged.min,
        max: merged.max,
        tooltips: (merged.tooltips === undefined ? false : merged.tooltips),
        ...(merged.props || {}),
        ...(merged.options || {}),
      };
      const opts = coerceWidgetOptions('RangeSlider', optsRaw);
      is.addWidgets([ rangeSlider(opts) ]);
      break;
    }

    case 'Toggle': {
      const opts = { container: el, attribute: merged.attribute, ...(merged.props || {}), ...(merged.options || {}) };
      is.addWidgets([ toggleRefinement(opts) ]);
      break;
    }

    case 'NumericMenu': {
      const opts = { container: el, attribute: merged.attribute, ...(merged.props || {}), ...(merged.options || {}) };
      is.addWidgets([ numericMenu(opts) ]);
      break;
    }

    case 'RatingMenu': {
      const opts = { container: el, attribute: merged.attribute, ...(merged.props || {}), ...(merged.options || {}) };
      is.addWidgets([ ratingMenu(opts) ]);
      break;
    }

    case 'Menu': {
      const opts = {
        container: el,
        attribute: merged.attribute,
        transformItems: (items) => transformWithLookup(ctrl, items, merged.attribute, merged.lookup || {}),
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

      // default searchable=false when we're in "contains" mode (we render our own input)
      const searchable = (merged.searchMode === 'prefix')
        ? (merged.searchable ?? !DEFAULT_NON_SEARCHABLE.has(merged.attribute))
        : false;

      // optional local "contains" input
      let containsInput = null;
      if (merged.searchMode !== 'prefix') {
        // create/query a local input above the list
        containsInput = document.createElement('input');
        containsInput.type = 'search';
        containsInput.placeholder = 'Search…';
        containsInput.className = 'form-control form-control-sm mb-2';
        body.insertBefore(containsInput, el);

        if (!ctrl._facetLocalQuery) ctrl._facetLocalQuery = new Map();
        ctrl._facetLocalQuery.set(attribute, '');

        containsInput.addEventListener('input', () => {
          ctrl._facetLocalQuery.set(attribute, String(containsInput.value || '').toLowerCase());
          ctrl._is.refresh(); // re-render facet with client-side filter
        });
      }

      const opts = {
        container: el,
        attribute: merged.attribute,
        limit: merged.limit ?? 10,
        showMore: true,
        showMoreLimit: merged.showMoreLimit ?? 30,
        searchable, // only true in prefix mode
        sortBy,
        /**
         * Order of operations:
         *  1) Start from IS items (respect engine, disjunctive logic)
         *  2) If baseline (no query/refinements and no facet-contains text), union global distribution
         *  3) If contains-mode and contains text exists, build from global distribution and filter by substring
         *  4) Backfill highlighted for all
         *  5) Slice to the original items.length to preserve limit/showMore exactly
         */
        transformItems: (items) => {
          const ISlen = items.length;    // the visual budget IS intends to render
          const state = ctrl._is?.helper?.state;
          const refined = (state?.disjunctiveFacetsRefinements?.[merged.attribute] || []).length > 0
                       || (state?.facetsRefinements?.[merged.attribute] || []).length > 0;
          const hasQuery = !!state?.query;
          const globalMap = ctrl._globalFacetCounts?.[merged.attribute] || null;

          let out = transformWithLookup(ctrl, items, merged.attribute, merged.lookup || {});

          // contains-mode: if user typed, prefer global list filtered by substring
          const containsQ = (merged.searchMode !== 'prefix')
            ? (ctrl._facetLocalQuery?.get(merged.attribute) || '').trim().toLowerCase()
            : '';

          if (merged.searchMode !== 'prefix' && containsQ && globalMap) {
            // Build from global for full coverage
            out = Object.entries(globalMap)
              .map(([val, cnt]) => buildItem(val, cnt))
              .filter(it => toStr(it.label).toLowerCase().includes(containsQ));
          } else if (globalMap && !hasQuery && !refined) {
            // baseline union to show everything (but we'll slice to ISlen later)
            const present = new Set(out.map(it => toStr(it.value)));
            for (const [val, cnt] of Object.entries(globalMap)) {
              const key = toStr(val);
              if (!present.has(key)) out.push(buildItem(val, cnt));
            }
            // rewrite counts to global at baseline
            out = out.map(it => ({ ...it, count: (globalMap[toStr(it.value)] ?? it.count) }));
          }

          // backfill highlighted if missing
          out = out.map(it => ({
            ...it,
            highlighted: (typeof it.highlighted === 'undefined' || it.highlighted === null)
              ? toStr(it.label ?? it.value)
              : it.highlighted
          }));

          // IMPORTANT: respect IS' own budget for this render to keep "show more" exact
          if (ISlen >= 0 && out.length > ISlen) out = out.slice(0, ISlen);

          return out;
        },
        ...(merged.props || {}),
        ...(merged.options || {}),
      };

      const widgetObj = refinementList(opts);
      is.addWidgets([ widgetObj ]);
      ctrl._facetWidgets.set(merged.attribute, widgetObj);
      break;
    }
  }
}

/**
 * Rebuild one RefinementList with a new sort mode ('count' | 'alpha').
 * Preserves the above behaviors, including contains-mode filtering and slicing to IS budget.
 */
export function rebuildRefinementList(ctrl, merged, el, newSortMode) {
  const is = ctrl._is;
  if (!is) return;

  const old = ctrl._facetWidgets.get(merged.attribute);
  if (old) { try { is.removeWidgets([old]); } catch { /* ignore */ } }

  const sortBy = newSortMode === 'alpha'
    ? ['isRefined:desc','name:asc','count:desc']
    : ['isRefined:desc','count:desc','name:asc'];

  const searchMode = merged.searchMode || 'contains';
  const searchable = (searchMode === 'prefix')
    ? (merged.searchable ?? !DEFAULT_NON_SEARCHABLE.has(merged.attribute))
    : false;

  const widget = refinementList({
    container: el,
    attribute: merged.attribute,
    limit: merged.limit ?? 10,
    showMore: true,
    showMoreLimit: merged.showMoreLimit ?? 30,
    searchable,
    sortBy,
    transformItems: (items) => {
      const ISlen = items.length;
      const state = ctrl._is?.helper?.state;
      const refined = (state?.disjunctiveFacetsRefinements?.[merged.attribute] || []).length > 0
                   || (state?.facetsRefinements?.[merged.attribute] || []).length > 0;
      const hasQuery = !!state?.query;
      const globalMap = ctrl._globalFacetCounts?.[merged.attribute] || null;

      let out = transformWithLookup(ctrl, items, merged.attribute, merged.lookup || {});

      const containsQ = (searchMode !== 'prefix')
        ? (ctrl._facetLocalQuery?.get(merged.attribute) || '').trim().toLowerCase()
        : '';

      if (searchMode !== 'prefix' && containsQ && globalMap) {
        out = Object.entries(globalMap)
          .map(([val, cnt]) => buildItem(val, cnt))
          .filter(it => toStr(it.label).toLowerCase().includes(containsQ));
      } else if (globalMap && !hasQuery && !refined) {
        const present = new Set(out.map(it => toStr(it.value)));
        for (const [val, cnt] of Object.entries(globalMap)) {
          const key = toStr(val);
          if (!present.has(key)) out.push(buildItem(val, cnt));
        }
        out = out.map(it => ({ ...it, count: (globalMap[toStr(it.value)] ?? it.count) }));
      }

      out = out.map(it => ({
        ...it,
        highlighted: (typeof it.highlighted === 'undefined' || it.highlighted === null)
          ? toStr(it.label ?? it.value)
          : it.highlighted
      }));

      if (ISlen >= 0 && out.length > ISlen) out = out.slice(0, ISlen);
      return out;
    },
    ...(merged.props || {}),
    ...(merged.options || {}),
  });

  is.addWidgets([ widget ]);
  ctrl._facetWidgets.set(merged.attribute, widget);
}
