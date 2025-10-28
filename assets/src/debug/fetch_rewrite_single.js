/**
 * Dev-only rewrite for single-query /multi-search → /indexes/{uid}/search
 * Goal: prove server-side rankingScoreThreshold applies outside of multi-search path.
 * Safe to enable/disable in dev. No build-time changes needed.
 */
(() => {
  if (window.__FETCH_REWRITE_SINGLE_INSTALLED__) return;
  window.__FETCH_REWRITE_SINGLE_INSTALLED__ = true;

  const origFetch = window.fetch;

  window.fetch = async function rewriteSingle(input, init, ...rest) {
    try {
      const url = typeof input === 'string' ? input : (input?.url ?? '');
      const method = (init?.method || 'GET').toUpperCase();

      const isMulti = url.includes('/multi-search') && method === 'POST';
      if (!isMulti) {
        return origFetch.apply(this, [input, init, ...rest]);
      }

      // Parse the multi-search body
      let payload = null;
      if (init?.body && typeof init.body === 'string') {
        try { payload = JSON.parse(init.body); } catch {}
      }
      if (!payload || !Array.isArray(payload.queries) || payload.queries.length !== 1) {
        // Not a single-query — leave it alone.
        return origFetch.apply(this, [input, init, ...rest]);
      }

      const q0 = payload.queries[0] ?? {};
      const indexName = q0.indexName || q0.indexUid || q0.uid;
      if (!indexName) {
        return origFetch.apply(this, [input, init, ...rest]);
      }

      // Build body expected by /indexes/{uid}/search
      // Start with params, then mirror known top-level (q, hybrid, rankingScoreThreshold, tags)
      const body = {
        ...(q0.params ?? {}),
      };

      if (q0.q && !body.q) body.q = q0.q;
      if (q0.query && !body.q) body.q = q0.query; // tolerate either

      if (q0.hybrid) body.hybrid = q0.hybrid;
      if (q0.params?.hybrid && !body.hybrid) body.hybrid = q0.params.hybrid;

      if (typeof q0.rankingScoreThreshold === 'number') {
        body.rankingScoreThreshold = q0.rankingScoreThreshold;
      }
      if (typeof q0.params?.rankingScoreThreshold === 'number') {
        body.rankingScoreThreshold = q0.params.rankingScoreThreshold;
      }

      if (q0.highlightPreTag && !body.highlightPreTag) body.highlightPreTag = q0.highlightPreTag;
      if (q0.highlightPostTag && !body.highlightPostTag) body.highlightPostTag = q0.highlightPostTag;

      // Pass-through other common params that some adapters shuffle
      // (hitsPerPage/page/facets/sort/filter/showRankingScore...)
      // They’re already in q0.params from your controller patch.

      const urlObj = new URL(url);
      const host = `${urlObj.protocol}//${urlObj.host}`;
      const singleUrl = `${host}/indexes/${encodeURIComponent(indexName)}/search`;

      // Log what we’re doing for visibility
      console.groupCollapsed('%cREWRITE → /indexes/'+indexName+'/search (single-query)', 'color:#0a0');
      console.log(body);
      console.groupEnd();

      const newInit = {
        ...init,
        body: JSON.stringify(body),
      };

      return origFetch.call(this, singleUrl, newInit, ...rest);
    } catch (e) {
      // Fallback to original request on any unexpected parse/logic error
      console.warn('[fetch_rewrite_single] error; falling back to original fetch', e);
      return origFetch.apply(this, [input, init, ...rest]);
    }
  };
})();
