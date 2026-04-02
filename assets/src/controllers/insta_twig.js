/**
 * insta_twig.js — engine bootstrap for meili-bundle.
 *
 * Creates the twig-browser engine and wires:
 *   - sais_encode()  — meili-specific URL-safe base64
 *   - path()         — from the SurvosJsTwigBundle-generated FOS routing module
 *                      (@survos/js-twig/generated/fos_routes.js). Silently skips
 *                      if the module isn't present (e.g. bundle not installed).
 *
 * ux_icon() and stimulus_* are built into createEngine() and need no wiring here.
 */

import { createEngine } from '@tacman1123/twig-browser';
import { installSymfonyTwigAPI } from '@tacman1123/twig-browser/adapters/symfony';

let _engine = null;
let _engineReady = null; // Promise that resolves once async setup is done

/**
 * Create (once) and return the shared twig-browser engine.
 * Returns the engine synchronously; path() is wired asynchronously in the
 * background — call awaitTwigEngine() if you need it fully ready first.
 */
export function installTwigEngine() {
  if (_engine) return _engine;
  _engine = createEngine();

  // meili-specific: URL-safe base64 encode used by some index templates.
  _engine.registerFunction('sais_encode', (url) =>
    btoa(url).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
  );

  // Wire Symfony Twig helpers (incl. path()) from generated FOS routes.
  _engineReady = import('@survos/js-twig/generated/fos_routes.js')
    .then(({ path }) => {
      installSymfonyTwigAPI(_engine, { pathGenerator: path });
    })
    .catch((error) => {
      const msg = [
        '[meili-bundle] path() is unavailable in twig-browser.',
        'Install and enable Survos JS Twig routing support:',
        '  composer require survos/js-twig-bundle',
        'Then clear cache so @survos/js-twig/generated/fos_routes.js is generated.',
      ].join('\n');
      console.error(msg, error);
      throw new Error(msg);
    });

  return _engine;
}

/**
 * Resolves once the engine is fully set up (including path() wiring).
 * Await this before rendering templates that call path().
 */
export async function awaitTwigEngine() {
  if (!_engine) installTwigEngine();
  await _engineReady;
  return _engine;
}

/**
 * Return the shared engine instance (null until installTwigEngine is called).
 */
export function getTwigEngine() {
  return _engine;
}
