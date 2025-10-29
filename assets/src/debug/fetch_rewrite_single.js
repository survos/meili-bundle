// assets/src/debug/fetch_rewrite_single.js
// Transparently rewrite single-query /multi-search → /indexes/{indexUid}/search
// while PRESERVING all supported Meili search parameters.

(function() {
  const ORIG = window.fetch;

  const PASSTHRU = new Set([
    // core
    'q','filter','sort','distinct','offset','limit','page','hitsPerPage',
    // facets
    'facets','facetDistribution',
    // attributes
    'attributesToRetrieve','attributesToSearchOn',
    'attributesToHighlight','attributesToCrop','cropLength','cropMarker',
    // highlights / matches
    'highlightPreTag','highlightPostTag','showMatchesPosition',
    // ranking / scoring
    'showRankingScore','showRankingScoreDetails','rankingScoreThreshold',
    // semantic / hybrid
    'hybrid','vector','retrieveVectors',
    // other
    'matchingStrategy','locales'
  ]);

  function cloneInit(init) {
    if (!init) return {};
    const copy = { ...init };
    if (init.headers instanceof Headers) {
      copy.headers = new Headers(init.headers);
    } else if (init.headers) {
      copy.headers = { ...init.headers };
    }
    return copy;
  }

  async function rewriteIfNeeded(input, init) {
    try {
      const url = typeof input === 'string' ? input : input.url;
      if (!url || !/\/multi-search(?:\?|$)/.test(url)) return null;

      const bodyText = init?.body ? (typeof init.body === 'string' ? init.body : await init.body.text?.()) : '';
      const parsed = bodyText ? JSON.parse(bodyText) : {};
      const queries = Array.isArray(parsed?.queries) ? parsed.queries : [];

      // Only rewrite when there is exactly one query
      if (queries.length !== 1) return null;

      const q0 = queries[0] || {};
      const indexUid = q0.indexUid || q0.index || q0.indexName;
      if (!indexUid) return null;

      const outBody = {};
      for (const [k, v] of Object.entries(q0)) {
        if (PASSTHRU.has(k)) outBody[k] = v;
      }

      // Ensure we don’t drop required fields we rely on for UI
      if (!outBody.attributesToRetrieve) outBody.attributesToRetrieve = ['*','_formatted'];
      if (!outBody.attributesToHighlight) outBody.attributesToHighlight = ['*'];
      if (!outBody.highlightPreTag)  outBody.highlightPreTag  = '<mark class="ais-Highlight">';
      if (!outBody.highlightPostTag) outBody.highlightPostTag = '</mark>';
      outBody.showRankingScore = true;
      outBody.showRankingScoreDetails = true;

      // Build new URL and init
      const base = url.replace(/\/multi-search(?:\?.*)?$/, '');
      const newUrl = `${base}/indexes/${encodeURIComponent(indexUid)}/search`;
      const newInit = cloneInit(init);
      newInit.method = 'POST';
      if (newInit.headers instanceof Headers) {
        newInit.headers.set('Content-Type', 'application/json');
      } else {
        newInit.headers = { ...(newInit.headers || {}), 'Content-Type': 'application/json' };
      }
      newInit.body = JSON.stringify(outBody);

      return { url: newUrl, init: newInit };
    } catch (_e) {
      // If anything goes wrong, fall through to original request untouched
      return null;
    }
  }

  window.fetch = async function(input, init) {
    const rewrite = await rewriteIfNeeded(input, init);
    if (rewrite) {
      return ORIG(rewrite.url, rewrite.init);
    }
    return ORIG(input, init);
  };
})();
