import Twig from 'twig';

/**
 * Install Twig helpers into Twig.js.
 * Pass in optional Routing + StimAttrs to keep this file reusable.
 */
export function installTwigAPI({ Routing = null, StimAttrs }) {
  Twig.extend((TwigApi) => {
    TwigApi._function.extend('path', (route, routeParams = {}) => {
      if (!Routing) return `#install-fos-routing(${String(route)})`;
      if (routeParams && typeof routeParams === 'object' && '_keys' in routeParams) delete routeParams._keys;
      return Routing.generate(route, routeParams);
    });
    TwigApi._function.extend('stimulus_controller', (n, v = {}, c = {}, o = {}) =>
      StimAttrs.stimulus_controller(n, v, c, o));
    TwigApi._function.extend('stimulus_target', (n, t = null) =>
      StimAttrs.stimulus_target(n, t));
    TwigApi._function.extend('stimulus_action', (n, a, e = null, p = {}) =>
      StimAttrs.stimulus_action(n, a, e, p));
    TwigApi._function.extend('sais_encode', (url) =>
      btoa(url).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''));

    TwigApi._function.extend('ux_icon', (name, attrs = {}) => {
      if (!name) return '';
      const map = (window.__survosIconsMap || {});
      const svg = map[name];
      if (!svg) { console.warn('[insta] ux_icon not found:', name); return ''; }
      if (attrs && typeof attrs === 'object' && attrs.class) {
        return `<span class="${String(attrs.class)}">${svg}</span>`;
      }
      return svg;
    });
  });
}
