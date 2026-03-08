import { createEngine } from '@tacman1123/twig-browser';

/**
 * Wire path() into the engine using the cache-warmer-generated routing module.
 * Dynamic import so the module is optional — silently skips if unavailable.
 */
export async function installFosRouting(engine) {
  try {
    const { path } = await import('@survos/js-twig/generated/fos_routes.js');
    engine.registerFunction('path', path);
    return true;
  } catch {
    // FOS routing not available — path() will throw a clear error if called in a template
    return false;
  }
}

// Module-level engine singleton – created once installTwigEngine is called.
let _engine = null;

/**
 * Return the shared engine instance (null until installTwigEngine is called).
 */
export function getTwigEngine() {
  return _engine;
}

/**
 * Create and configure the @tacman1123/twig-browser engine.
 *
 * - stimulus_controller/target/action are built into createEngine()
 * - ux_icon() is handled automatically by createEngine() via window.__survosIconsMap
 * - path() is wired via installFosRouting() from the cache-warmer-generated module
 * - sais_encode() is a meili-specific extra
 */
export function installTwigEngine() {
  _engine = createEngine();

  // meili-specific: URL-safe base64 encode
  _engine.registerFunction('sais_encode', (url) =>
    btoa(url).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
  );

  return _engine;
}
