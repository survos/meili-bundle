/**
 * Minimal "debug" shim for browser environments.
 * Enable with: localStorage.debug = 'insta:*,wire:*,hl:*,view:*' (comma/space separated)
 * Supports "*" wildcards anywhere (e.g. "*", "insta:*", "insta:*:details").
 *
 * If a real debug impl is provided at globalThis.__DEBUG_CREATE__, we use that.
 */
function safeGetDebugConfig() {
  try {
    return (localStorage && localStorage.getItem('debug')) || '';
  } catch (_) {
    return '';
  }
}

function patternToRegex(pat) {
  // escape regex specials, then turn \* into .*
  const escaped = pat.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp('^' + escaped.replace(/\\\*/g, '.*') + '$');
}

function isEnabledFor(ns, config) {
  if (!config) return false;
  const parts = config.split(/[,\s]+/).filter(Boolean);
  if (parts.length === 0) return false;
  return parts.some(p => patternToRegex(p).test(ns));
}

function shimCreateDebug(namespace) {
  const cfg = safeGetDebugConfig();
  const enabled = isEnabledFor(namespace, cfg);

  const logger = (...args) => {
    if (!enabled) return;
    // Use console.debug so it won’t clutter normal logs unless dev tools shows it
    console.debug(`[${namespace}]`, ...args);
  };

  // Keep an "enabled" flag similar to the real "debug" library
  Object.defineProperty(logger, 'enabled', { get: () => isEnabledFor(namespace, safeGetDebugConfig()) });

  // Provide a minimal ".extend" API
  logger.extend = (suffix) => shimCreateDebug(namespace + (suffix.startsWith(':') ? suffix : ':' + suffix));

  return logger;
}

// If a real debug creator is exposed globally, prefer it.
// You can wire this by loading the real 'debug' somewhere and setting:
//   globalThis.__DEBUG_CREATE__ = debug;
export const createDebug = globalThis.__DEBUG_CREATE__ || shimCreateDebug;
