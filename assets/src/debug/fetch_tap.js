/**
 * Dev-only fetch tap: logs the ACTUAL wire payload to /multi-search.
 * Safe to import anywhere (no side effects except logging).
 */
(() => {
  if (window.__FETCH_TAP_INSTALLED__) return;
  window.__FETCH_TAP_INSTALLED__ = true;

  const orig = window.fetch;
  window.fetch = async function tappedFetch(input, init, ...rest) {
    try {
      const url = typeof input === 'string' ? input : (input?.url ?? '');
      const method = (init?.method || 'GET').toUpperCase();
      if (url.includes('/multi-search') && method === 'POST') {
        let body = init?.body;
        let parsed = null;
        if (body && typeof body === 'string') {
          try { parsed = JSON.parse(body); } catch {}
        }
        console.groupCollapsed('%cWIRE → /multi-search', 'color:#06f');
        console.log(parsed ?? body ?? '(no body)');
        console.groupEnd();
      }
    } catch {}
    return orig.apply(this, [input, init, ...rest]);
  };
})();
