import { createEngine } from '@tacman1123/twig-browser';
import { installSymfonyTwigAPI, installFosRoutingFromData } from '@tacman1123/twig-browser/adapters/symfony';
import { routingData } from '@survos/js-twig/generated/fos_routes.js';

// Re-export so callers only need to import from this file.
export { installFosRoutingFromData };

export function installFosRouting(engine) {
  return installFosRoutingFromData(engine, routingData);
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
 * - path() is wired via installFosRouting() from the statically-imported generated module
 * - ux_icon() reads from window.__survosIconsMap, populated at initialize() time
 * - sais_encode() is a meili-specific extra
 *
 * Returns the engine so callers can pass it to installFosRouting().
 */
export function installTwigEngine() {
  _engine = createEngine();

  // ux_icon: reads from the icon map the controller populates at initialize()
  installSymfonyTwigAPI(_engine, {
    uxIconResolver: (name, attrs = {}) => {
      if (!name) return '';
      const svg = (window.__survosIconsMap || {})[name];
      if (!svg) {
        console.warn(`[insta] ux_icon("${name}") not found — run: bin/console ux:icons:lock`);
        // Fallback: render icon name as a small text badge so it's visible but not broken
        return `<span class="ux-icon-fallback" title="${name}" style="font-size:.75em;opacity:.6">${name}</span>`;
      }
      return (attrs?.class)
        ? `<span class="${String(attrs.class)}">${svg}</span>`
        : svg;
    }
  });

  // meili-specific: URL-safe base64 encode
  _engine.registerFunction('sais_encode', (url) =>
    btoa(url).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
  );

  return _engine;
}
