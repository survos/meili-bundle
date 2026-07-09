# Frontend InstantSearch UI (Stimulus)

How to wire a Meilisearch-backed search page in a consuming app using the bundle's
generic `@survos/meili-bundle/insta` Stimulus controller. This is the part of the
bundle that's easy to get wrong — several apps have independently reinvented a
bespoke JS controller instead of using this one, and every one of them hit the same
missing-CSS problem. Read this before writing any app-specific search JS.

## The rule: don't write a Stimulus controller for this

`@survos/meili-bundle/insta` (`bu/meili-bundle/assets/src/controllers/insta_controller.js`)
already does search box, facets, hits, pagination, routing (bookmarkable URLs),
hybrid/semantic search, and a JSON detail modal — entirely driven by Stimulus values
and DOM data-attributes. If you find yourself writing `instantsearch({...})` and
`addWidgets([...])` by hand in an app, stop — you're duplicating this controller
and will independently rediscover its bugs. If the controller is genuinely missing
something, extend it (or listen for its events — see "Custom behavior" below), don't
build a parallel one.

## 1. Minimal page markup

```twig
{% set _sc = '@survos/meili-bundle/insta' %}

<div {{ stimulus_controller(_sc, {
    serverUrl: serverUrl,
    serverApiKey: apiKey,
    indexName: indexUid,
    templateUrl: templateUrl,
    globalsJson: { userLocale: app.request.locale }|json_encode,
}) }}>
    <div {{ stimulus_target(_sc, 'stats') }}></div>
    <div {{ stimulus_target(_sc, 'searchBox') }}></div>

    <aside {{ stimulus_target(_sc, 'refinementList') }}>
        <div data-attribute="year" data-widget="RangeSlider" data-pips="false"></div>
        <div data-attribute="category" data-widget="RefinementList"
             data-searchable="true" data-search-mode="prefix"
             data-limit="8" data-show-more-limit="20"></div>
    </aside>

    <div {{ stimulus_target(_sc, 'hits') }}></div>
    <div {{ stimulus_target(_sc, 'pagination') }}></div>
</div>
```

Facets are **not** individual Stimulus targets — declare every facet as a
`data-attribute`/`data-widget` node inside the single `refinementList` target.
`insta_facets.js` scans `refinementListTarget.querySelectorAll('[data-attribute][data-widget]')`
and mounts one InstantSearch widget per node, in DOM order. `data-widget` is
`RefinementList` (default), `Menu`, or `RangeSlider`. See
`insta_facets.js`'s docblock for the full `data-*` option list (searchable, limit,
show-more, sort-mode, etc.).

`routing: true` (bookmarkable search state in the URL) is the controller's default —
you don't need to configure it.

## 2. Hit template: a real `.twig` file, not JS

Don't embed the hit markup as a JS template-literal string. Put it in
`templates/js/{indexBase}.html.twig` (unprefixed base index name, e.g. `fs_fortepan`,
not the physical prefixed UID) and pass:

```php
'templateUrl' => $this->generateUrl('meili_template', ['templateName' => $indexBase]),
```

The controller fetches this at `connect()` time via `loadTemplateFromUrl()` and
compiles it client-side with twig-browser — editing the card is a `.twig` change,
no JS rebuild, no deploy of compiled assets. `TemplateController::jsTemplate()`
resolves `{templateName}` through a fallback chain (unprefixed → aggregator prefix →
full prefixed name → `default`), so name the file after the *raw* index base, and
falling back to `templates/js/default.html.twig` for indexes without a bespoke
template is free.

Inside the template, `hit` is the raw document and `globals` is whatever you passed
in `globalsJson` (e.g. `globals.userLocale`) — see
`insta_controller.js`'s `templates.item` context for the full var list
(`_config`, `_score`, `icons`, `_sc_modal`, `hints`, `view`).

## 3. The CSS you *will* forget (this is the actual gotcha)

Two separate stylesheets are required, and neither is wired up automatically:

**a) `instantsearch.css`'s algolia theme** — provides base InstantSearch component
structure. `assets/controllers.json`'s recipe entry for `insta` deliberately sets
`"instantsearch.css/themes/algolia.min.css": false` (no autoimport) — the bundle
can't know where in *your* app's CSS load order it's safe to inject this without
risking it fighting your design system's own resets (Tabler/Bootstrap components
overlap with `.ais-*` selectors non-trivially).

  - If your app doesn't use a component framework: just `import` it in `app.js`,
    done (see `docs/installation.md` step 7).
  - If your app uses Tabler (every app in this ecosystem does, in practice — see
    "Portability" below): import it from a **dedicated entrypoint** that imports
    your normal `app.js` *first*, so Tabler's CSS registers before algolia's:

    ```js
    // assets/meili.js — loaded only on pages with the insta controller
    import './app.js';
    import 'instantsearch.css/themes/algolia.min.css';
    ```

    Register it in `importmap.php` (`'meili' => ['path' => './assets/meili.js', 'entrypoint' => true]`)
    and override the `javascripts` block on the search page's template only:

    ```twig
    {% block javascripts %}{{ importmap('meili') }}{% endblock %}
    ```

    Caveat confirmed experimentally: AssetMapper's static `<link rel="preload">`
    generation does **not** reliably preserve the JS import order for extracted
    CSS — don't trust it blind. `meili.css` (next section) works around this by
    using `!important` on its layout-critical rules, which is the pragmatic
    reason it's robust regardless of link order.

**b) `bu/meili-bundle/public/meili.css`** — the bundle's own polish layer:
refinement-list row spacing/checkbox styling, range slider handles, and the hits
grid (`.grid-1` through `.grid-5`, matching `insta_controller.js`'s default
`hitClass="grid-3"`). This is a **plain bundle public asset** (`public/`, installed
by Symfony's `assets:install`/`bin/console assets:install`), not an AssetMapper
module — nothing auto-links it. You must add the `<link>` yourself:

```twig
{% block stylesheets %}
    {{ parent() }}
    <link rel="stylesheet" href="{{ asset('bundles/survosmeili/meili.css') }}">
{% endblock %}
```

Forgetting this is the single most common failure mode: the page loads, search
works, but refinement lists render as an unstyled bullet list and pagination looks
broken. If you see that, this missing `<link>` is almost certainly why — check
this before writing any custom CSS to patch it.

## 4. Same index, multiple apps: MEILI_PREFIX must match

If one app builds an index (e.g. a data-pipeline app) and a different app serves
search over it, both apps' `MEILI_PREFIX` must be identical. `IndexNameResolver::uidForRaw()`
computes the physical Meilisearch index name as `prefix . rawName` — a mismatch
means the builder writes to a *different physical index* than the server reads
from, silently. There's no error; the serving app just always sees an empty (or
stale) index. Check `MEILI_PREFIX` in both apps' `.env`/`.env.local` before
debugging anything else if a freshly-built index "isn't showing up."

## Portability vs. reality

The bundle's docs/comments generally describe it as framework-agnostic
("`meili.css` ... Safe to use without Bootstrap/Tabler"), and `insta_controller.js`
has no hard Tabler dependency. In practice every real consumer in this ecosystem
uses Tabler, so app-level entrypoint files (like `assets/meili.js` above) can and
should just assume Tabler unconditionally — that's app code, not bundle code, so
there's no portability constraint to honor there. Don't add conditional-Tabler
logic to solve a problem that doesn't exist yet.

## Custom behavior without forking the controller

If a page genuinely needs behavior `insta_controller.js` doesn't have, prefer (in
order):
1. A `data-*` option on a facet node, if it's facet-specific — check
   `insta_facets.js` first, it has more knobs than it looks like.
2. Listening for the controller's own events / the underlying InstantSearch
   instance (`window.search` is set by the controller) from a *separate*,
   small Stimulus controller — don't reach into or copy `insta_controller.js`.
3. Extending the bundle controller itself, if the behavior is broadly useful.

Writing a second, parallel `instantsearch()` setup for one app's quirks is how we
got here — a dedicated search *page* per app instead of a search *component* any
page can drop in. That's a real architectural gap (raised in the zm folioSet work,
2026-07-09) worth fixing at the bundle level eventually, not by working around it
per-app again.
