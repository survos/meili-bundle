import { refinementList, rangeSlider, menu } from 'instantsearch.js/es/widgets';
import { safeParse } from './insta_helpers.js';

/**
 * Mount a single facet widget from a DOM node prepared by Twig.
 * Node must have: data-attribute and data-widget (RefinementList|Menu|RangeSlider)
 */
export function mountFacetFromNode(ctrl, instantSearch, el) {
  if (!el || !el.getAttribute) return;

  const attribute   = el.getAttribute('data-attribute');
  const widgetName  = el.getAttribute('data-widget') || 'RefinementList';
  let   sortMode    = (el.getAttribute('data-sort-mode') || 'count').toLowerCase();

  // dataset from Twig
  const limitAttr         = el.getAttribute('data-limit');
  const showMoreLimitAttr = el.getAttribute('data-show-more-limit');
  const searchableAttr    = el.getAttribute('data-searchable'); // "true" | "false" | null
  const lookupJson        = el.getAttribute('data-lookup') || '{}';

  // facet defaults (from compiled configJson if present)
  const facetConf = (ctrl.config?.facets && (ctrl.config.facets[attribute] || ctrl.config.facets?.find?.(f => f.attribute === attribute))) || {};

  const limit         = toIntOr(limitAttr, facetConf.limit ?? 5);
  const showMoreLimit = toIntOr(showMoreLimitAttr, facetConf.showMoreLimit ?? 10);

  // precedence: data-searchable → facet.searchable → default(false)
  const searchable = searchableAttr === 'true'
    ? true
    : (searchableAttr === 'false'
        ? false
        : (typeof facetConf.searchable === 'boolean' ? facetConf.searchable : false));

  const lookup = safeParse(lookupJson, {});
  const transformItems = (items) => {
    if (!lookup || Object.keys(lookup).length === 0) return items;
    return items.map(item => ({
      ...item,
      label: lookup[item.value] ?? item.label,
      highlighted: lookup[item.value] ?? item.highlighted ?? item.label
    }));
  };

  /* ---------- mount helpers ---------- */

  let currentWidget = null;

  const mountRange = () => {
    const step = toIntOr(el.getAttribute('data-step'), undefined);
    const min  = toIntOr(el.getAttribute('data-min'), undefined);
    const max  = toIntOr(el.getAttribute('data-max'), undefined);
    const pips = attrBool(el.getAttribute('data-pips'), false);
    const tooltips = parseTooltips(el.getAttribute('data-tooltips'), ctrl);

    currentWidget = rangeSlider({
      container: el,
      attribute,
      ...(typeof step === 'number' ? { step } : {}),
      ...(typeof min  === 'number' ? { min  } : {}),
      ...(typeof max  === 'number' ? { max  } : {}),
      ...(tooltips ? { tooltips } : {}),
      pips
    });
    instantSearch.addWidgets([currentWidget]);
  };

  const mountList = () => {
    const sortBy = (sortMode === 'name' || sortMode === 'alpha')
      ? ['name:asc']
      : ['count:desc'];

    const base = {
      container: el,
      attribute,
      limit,
      showMoreLimit,
      showMore: showMoreLimit > limit,
      searchable,
      sortBy,
      transformItems
    };

    currentWidget = (widgetName === 'Menu') ? menu(base) : refinementList(base);
    instantSearch.addWidgets([currentWidget]);
  };

  const remount = () => {
    if (currentWidget) {
      try { instantSearch.removeWidgets([currentWidget]); } catch {}
      currentWidget = null;
    }
    if (widgetName === 'RangeSlider') mountRange(); else mountList();
  };

  // initial mount
  remount();

  /* ---------- header interactions (sibling controls) ---------- */

  // collapse toggle (button is in the sibling .facet-header)
  const block     = el.closest?.('.facet-block');
  const body      = block?.querySelector?.('.facet-body');
  const collapseB = block?.querySelector?.('[data-collapse-control][data-attribute="'+attribute+'"]');

  if (collapseB && body) {
    collapseB.addEventListener('click', () => {
      const expanded = collapseB.getAttribute('aria-expanded') !== 'false';
      const next = !expanded;
      collapseB.setAttribute('aria-expanded', String(next));
      body.style.display = next ? '' : 'none';
    });

    // honor initial state
    const initiallyExpanded = collapseB.getAttribute('aria-expanded') !== 'false';
    if (!initiallyExpanded) body.style.display = 'none';
  }

  // single sort toggle (count ⇆ name)
  const sortBtn = block?.querySelector?.('[data-sort-toggle][data-attribute="'+attribute+'"]');
  if (sortBtn && (widgetName === 'RefinementList' || widgetName === 'Menu')) {
    sortBtn.addEventListener('click', () => {
      const cur = (sortBtn.getAttribute('data-mode') || 'count').toLowerCase();
      const next = cur === 'count' ? 'name' : 'count';
      sortBtn.setAttribute('data-mode', next);
      sortBtn.setAttribute('aria-pressed', String(next === 'name'));
      sortMode = next;
      remount();
    });
  }
}

/* ---------- helpers ---------- */

function toIntOr(v, fallback) {
  if (v === null || v === undefined || v === '') return fallback;
  const n = parseInt(v, 10);
  return Number.isNaN(n) ? fallback : n;
}

function attrBool(v, fallback=false) {
  if (v === null || v === undefined) return fallback;
  const s = String(v).toLowerCase().trim();
  if (s === 'true' || s === '1' || s === '') return true;   // empty attr → present → true
  if (s === 'false' || s === '0') return false;
  return fallback;
}

function parseTooltips(v, ctrl) {
  if (!v) return null;
  const s = String(v).trim();
  if (s === 'true') return true;
  if (s === 'false') return false;
  try {
    const obj = JSON.parse(s);
    if (obj && obj.format === 'monthIndex') {
      return { format: (val) => monthIndexToLabel(val) };
    }
    return obj;
  } catch {
    return null;
  }

  function monthIndexToLabel(i) {
    const year  = Math.floor(i / 12);
    const month = i % 12;
    const d     = new Date(year, month, 1);
    return d.toLocaleString('default', { year:'numeric', month:'short' });
  }
}
