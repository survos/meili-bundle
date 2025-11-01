// Minimal debug utilities for copy/paste back to ChatGPT.
// Single-line, stable-key logs: easier to diff & reason about.

export function envProbe(note = '') {
  const probe = {
    tag: 'MEILI_ENV',
    note,
    ts: new Date().toISOString(),
    hasWindow: typeof window !== 'undefined',
    hasDocument: typeof document !== 'undefined',
    hasRequire: typeof require === 'function', // should be false in browser
    userAgent: (typeof navigator !== 'undefined' && navigator.userAgent) || null
  };
  console.warn(JSON.stringify(probe));
  return probe;
}

export function twigProbe(Twig) {
  const info = {
    tag: 'MEILI_TWIG',
    ts: new Date().toISOString(),
    typeofTwig: typeof Twig,
    hasTwigFactory: !!(Twig && typeof Twig.twig === 'function'),
    keys: Twig ? Object.keys(Twig).slice(0, 12) : [],
  };
  // Detect common pitfall: Node build (needs fs/path) instead of browser build
  try {
    // eslint-disable-next-line no-undef
    // @ts-ignore
    info.hasNodeRequire = typeof require === 'function';
  } catch { info.hasNodeRequire = false; }

  console.warn(JSON.stringify(info));
  return info;
}

export function logRequestDraft(qs) {
  const brief = (q) => ({
    indexUid: q.indexUid,
    q: q.q,
    limit: q.limit,
    offset: q.offset,
    hasFacets: !!q.facets,
    filter: typeof q.filter === 'string' ? q.filter : null
  });
  console.warn(JSON.stringify({ tag:'MEILI_BUILT', ts: new Date().toISOString(), queries: (qs||[]).map(brief) }));
}

export function logRequestFinal(qs) {
  const brief = (q) => ({
    indexUid: q.indexUid,
    q: q.q,
    limit: q.limit,
    offset: q.offset,
    hasFacets: !!q.facets,
    filter: typeof q.filter === 'string' ? q.filter : null
  });
  console.warn(JSON.stringify({ tag:'MEILI_FINAL', ts: new Date().toISOString(), queries: (qs||[]).map(brief) }));
}

export function logResponseSummary(results) {
  try {
    const r0 = results?.[0] || {};
    const hits = Array.isArray(r0.hits) ? r0.hits : [];
    const scores = hits.map(h => Number(h?._rankingScore)).filter(Number.isFinite);
    const summary = {
      tag: 'MEILI_RESP',
      ts: new Date().toISOString(),
      nbHits: r0.nbHits ?? 0,
      hitsOnPage: hits.length,
      scoreMin: scores.length ? Math.min(...scores) : null,
      scoreMax: scores.length ? Math.max(...scores) : null
    };
    console.warn(JSON.stringify(summary));
  } catch (e) {
    console.warn(JSON.stringify({ tag:'MEILI_RESP_ERR', ts: new Date().toISOString(), err: String(e?.message || e) }));
  }
}
