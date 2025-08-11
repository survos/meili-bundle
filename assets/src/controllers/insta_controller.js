import {Controller} from '@hotwired/stimulus';


import Routing from "fos-routing";
import RoutingData from "/js/fos_js_routes.js";
import {prettyPrintJson} from 'pretty-print-json';
import Twig from 'twig';
import instantsearch from 'instantsearch.js'
import {instantMeiliSearch} from '@meilisearch/instant-meilisearch';
import {hits, pagination, refinementList, clearRefinements, rangeSlider,
    rangeInput, searchBox} from 'instantsearch.js/es/widgets'
import { infiniteHits, sortBy, configure } from 'instantsearch.js/es/widgets';

import {stimulus_action, stimulus_controller, stimulus_target,} from "stimulus-attributes";
import {Meilisearch} from "meilisearch";

import 'pretty-print-json/dist/css/pretty-print-json.min.css';
// this import makes the pretty-json really ugly
// import '@meilisearch/instant-meilisearch/templates/basic_search.css';
// @todo: custom css AFTER this.  Hack: move to app.css
// import 'instantsearch.css/themes/algolia.min.css';
// import '../../styles/custom-grid.css';
import 'flag-icons/css/flag-icons.min.css';
import "@andypf/json-viewer"

Routing.setData(RoutingData);


Twig.extend(function (Twig) {
    // Twig.setFilter('json_pretty', function(data, options={}) {
    //     return prettyPrintJson.toHtml(data, options);
    // });

    Twig._function.extend("json_pretty", (data, options={}) => {
        return prettyPrintJson.toHtml(data, options);
    })

    Twig._function.extend("path", (route, routeParams = {}) => {
        // console.error(routeParams);
        if ("_keys" in routeParams) {
            // if(routeParams.hasOwnProperty('_keys')){
            delete routeParams._keys; // seems to be added by twigjs
        }
        return Routing.generate(route, routeParams);
    });

    Twig._function.extend(
        "stimulus_controller",
        (
            controllerName,
            controllerValues = {},
            controllerClasses = {},
            controllerOutlets = ({} = {})
        ) =>
            stimulus_controller(
                controllerName,
                controllerValues,
                controllerClasses,
                controllerOutlets
            )
    );
    Twig._function.extend("stimulus_target", (controllerName, r = null) =>
        stimulus_target(controllerName, r)
    );
    Twig._function.extend(
        "stimulus_action",
        (controllerName, r, n = null, a = {}) =>
            stimulus_action(controllerName, r, n, a)
    );

});

const defaults = {
    base: '/templates',    // ← folder where .twig files live
};

// 2) Load a template file via AJAX
// const tpl = Twig.twig({
//     ...defaults,
//     // base: '/templatesXX',
//     href: '/index/detail.twig',  // ← path relative to `base`
//     load: true,                 // ← fetch it via XHR
//     async: false                // ← block until loaded
// });

// 3) Render it immediately
// const html = tpl.render({ title: 'Loaded via Twig.js!' });
// console.error(html);
// document.body.innerHTML = html;

/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://symfony.com/bundles/StimulusBundle/current/index.html#lazy-stimulus-controllers
*/

// /* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'searchBox',
        'hits',
        'template',
        'sort',
        'reset',
        'pagination',
        'refinementList',
        'marking']
    static values = {
        serverUrl: String,
        serverApiKey: String,
        indexName: String,
        embedderName: String,
        templateUrl: String,
        userLocale: {type: String, default: 'en'},
        hitClass: {type: String, default: 'grid-3'},
        globalsJson: {type: String, default: '{}'},
        iconsJson: {type: String, default: '{}'},
        sortingJson: {type: String, default: '{}'},
    }


    initialize() {
        console.log("Hello from " + this.identifier);
        // Called once when the controller is first instantiated (per element)

        // Here you can initialize variables, create scoped callables for event
        // listeners, instantiate external libraries, etc.
        // this._fooBar = this.fooBar.bind(this)
        this.globals = JSON.parse(this.globalsJsonValue);
        this.icons = JSON.parse(this.iconsJsonValue);
        this.sorting = JSON.parse(this.sortingJsonValue);
        this.regionNames = new Intl.DisplayNames(
            [this.userLocaleValue], {type: 'region'}
        );
        this.languageNames = new Intl.DisplayNames(
            [this.userLocaleValue], {type: 'language'}
        );
        // console.warn(this.embedderNameValue);
        console.error(this.templateUrlValue);

    }

    connect() {
        const self = this; // or use: const that = this;
        const ctrl = this;
        this._self = this;
        console.error(this.templateUrlValue);

        // Called every time the controller is connected to the DOM
        // (on page load, when it's added to the DOM, moved in the DOM, etc.)

        // Here you can add event listeners on the element or target elements,
        // add or remove classes, attributes, dispatch custom events, etc.
        // this.fooTarget.addEventListener('click', this._fooBar)

        this.fetchFile().then(() => {
                try {
                    this.search();
                } catch (e) {
                    this.hitsTarget.innerHTML = "URL: " + this.serverUrlValue + " " + e.message;
                }
        }
        )

    }

    async search() {
        const { searchClient, setMeiliSearchParams } = instantMeiliSearch(
        // const {searchClient} = instantMeiliSearch(
            this.serverUrlValue,
            this.serverApiKeyValue,
            {
                meiliSearchParams: {
                    // placeholderSearch: false, // default: true.
                    // primaryKey: 'id', // default: undefined
                    keepZeroFacets: false, // true,
                    showRankingScore: true,
                    showRankingScoreDetails: true,
                    semantic_ratio: 0.5,
                }
            }
        );

        window.setMeiliSearchParams = setMeiliSearchParams;
        window.searchClient = searchClient;

        const search = instantsearch({
            indexName: this.indexNameValue, // 'dummy_products', //
            searchClient,
            routing: true,
        });

        window.search = search;
        // search.addWidgets([
        //     configure({ hitsPerPage: 2,
        //         showRankingScore: false,
        //         showRankingScoreDetails: true,
        //         keepZeroFacets: false,
        //     }), // how many items per “page”, @todo: configurable
        //     hits({
        //         container: this.hitsTarget
        //     })
        // ]);
        // search.start();
        // search.refresh();
        // console.error(search, searchClient);
        // return;

        // setMeiliSearchParams({
        //     keepZeroFacets: false, // true,
        //     showRankingScore: true,
        //     showRankingScoreDetails: true,
        //     additionalSearchParams: { semantic_ratio: 0.7 },
        //     semantic_ratio: 0.5
        // });

        if (this.embedderNameValue) {
            const hybrid = {
                semantic_ratio: 0.2,
                embedder: this.embedderNameValue
            }
            console.log('🔄 Updating search params', hybrid)
        }


        // // An index is where the documents are stored.
        // // let client = searchClient.index;
        // this.rawMeiliSearch = new Meilisearch( {
        //         host: this.serverUrlValue,
        //         apiKey: this.serverApiKeyValue
        //     });


        // searchClient.search = async function(query, params) {
        //     console.log(query);
        //     console.log(params);
        //     return await searchClient.search(query, params);
        // }
        // let results = s('doll');

        // helpers to convert back and forth
        const toIndex = date => date.getFullYear() * 12 + date.getMonth();
        const fromIndex = i => {
            const year  = Math.floor(i / 12);
            const month = i % 12;
            const d     = new Date(year, month, 1);
            return d.toLocaleString('default', { year:'numeric', month:'short' });
        };

        // 3) Add widgets (including yours)
        // search.addWidgets([
        //     this.semanticRatioWidget({ container: '#semantic-widget' }),
        //     searchBox({ container: '#searchbox' }),
        //     hits({ container: '#hits' })
        // ]);

        if (this.embedderNameValue) {
            search.addWidgets([
                this.semanticRatioWidget(
                    {
                        container: '#semantic-widget',
                        searchController: this.searchController,
                    }
                )
            ]);
        } else {
            console.warn("No embeddder");
        }

        search.addWidgets([
            searchBox({
                container: this.searchBoxTarget,
                placeholder:  this.indexNameValue + ' on ' + this.serverUrlValue.replace(/(^\w+:|^)\/\//, '')
            }),
            sortBy({
                container: this.sortTarget,
                items: this.sorting
            }),
            configure({
                showRankingScore:       true,   // ← here, too
                hitsPerPage: 20 }), // how many items per “page”, @todo: configurable
            // hits({
            infiniteHits({
                container: this.hitsTarget,
                // escapeHTML: false,
                cssClasses: {
                    root: 'MyCustomHits',
                    item: this.hitClassValue,
                    list: ['MyCustomHitsList', 'MyCustomHitsList--subclass'],
                    loadMore: 'btn btn-primary',           // add your own styling
                    disabledLoadMore: 'btn btn-secondary disabled'
                },
                templates: {
                    loadMoreText: 'Load More',            // default: “Show more”
                    disabledLoadMoreText: 'No more results',
                    banner: ( {b}, {html}) => { console.error(b); return '' },
                    item: (hit, html, index) => {
                        //     <div class="hit-name">
                        //       {{#helpers.highlight}}{ "attribute": "name" }{{/helpers.highlight}}
                        //     </div>
                        if (hit.__position === 1)
                        {
                            console.log(hit);
                        }
                        this.globals._sc_locale = 'locale_display';
                        // idea: extend the language to have a
                        // generic debug: https://github.com/twigjs/twig.js/wiki/Extending-twig.js-With-Custom-Tags
                        // this _does_ work, with includes!
                        // let x= tpl.render({hit: hit, title: 'const tpl'});
                        // return hit.title + '/'; // JSON.stringify(hit);
                        return this.template.render({
                            x: '', // x,
                            hit: hit,
                            _sc_modal: '@survos/meili-bundle/json',
                            templateUrl: this.templateUrlValue,
                            icons: this.icons,
                            globals: this.globals
                        });
                    },
                },
            }),
            // pagination({
            //     container: this.paginationTarget
            // }),
        ]);

            search.addWidgets([
                clearRefinements({
                container: this.resetTarget,
                // also clears the query input:
                clearsQuery: true,
                // the text inside the button
                templates: {
                    reset: 'Reset all filters'
                },
                // style the <button> to look like a link
                cssClasses: {
                    button: 'btn btn-link p-0',
                    disabledButton: 'text-muted'  // optional: grey it out when nothing to clear
                }
            })
                ]
        );

        const attributeDivs = this.refinementListTarget.querySelectorAll('[data-attribute]')

        attributeDivs.forEach(div => {
            const attribute = div.getAttribute("data-attribute")
            const lookup = JSON.parse(div.getAttribute('data-lookup'));

            // const startDate = new Date(2003, 0, 1);      // Jan 2020
            // const endDate   = new Date(2022, 11, 1);     // Dec 2022

            if (["monthIndex"].includes(attribute)) {
                // https://stackoverflow.com/questions/71663103/how-to-set-the-title-for-a-rangeslider-in-instantsearch-js
                search.addWidgets([
                    rangeSlider({
                        container: div,
                        attribute: attribute, //  numeric field in MeiliSearch
                        // min: toIndex(startDate),           // integer endpoint
                        // max: toIndex(endDate),
                        step: 1,                            // one‐month granularity
                        tooltips: {
                            format: fromIndex                // show “Jan 2020”, “Feb 2020”, etc.
                        },
                        pips: false                         // turn off default Rheostat markers
                    })
                ]);
            } else if (["rating", "price", "stock", "year", "valueXX", "show", "starsXX"].includes(attribute)) {
                search.addWidgets([
                    rangeSlider({
                        container: div,
                        attribute: attribute,
                        pips: false,
                        tooltips: value =>
                            attribute === 'price'
                                ? '$' + new Intl.NumberFormat().format(value)
                                : value,
                    }),
                ]);
                // return;
            } else {
                let x = search.addWidgets([
                    refinementList({
                            container: div,
                            limit: 5,
                            showMoreLimit: 10,
                            showMore: true,
                            searchable: !['gender','house','currentParty','marking','countries','locale'].includes(attribute),
                            attribute: attribute,
                            // escapeHTML: false,
                            transformItems: (items, { results }) => {
                                if (['locale'].includes(attribute)) {
                                    return items.map(item => {
                                        // item.label = 'XX' + lookup[item.value] || item.value;
                                        // item.value = lookup[item.value];
                                        return {
                                            ...item,
                                            highlighted:
                                                this.languageNames.of(item.value.toUpperCase())
                                        };
                                    });

                                }
                                if (['countries', 'countryCode'].includes(attribute)) {
                                    return items.map(item => {
                                        // item.label = 'XX' + lookup[item.value] || item.value;
                                        // item.value = lookup[item.value];
                                        return {
                                            ...item,
                                            highlighted:
                                                this.regionNames.of(item.value.toUpperCase())
                                        };
                                    });
                                    // `<span class="fi fi-${item.value.toLowerCase()}"></span>`+

                                }
                                if (Object.keys(lookup).length === 0) {
                                    return items;
                                }
                                // let related = this.indexNameValue.replace(/obj$/, attribute);
                                // let related = 'm_px_victoria_type';
                                // let index = this.rawMeiliSearch.index(related);
                                // let index = this.searchClient.index(related);
                                // let yy = index.search('');
                                // yy.then(x => {
                                //     // console.log(attribute, related, x);
                                // })

                                // The 'results' parameter contains the full results data
                                return items.map(item => {
                                    item.label = lookup[item.value] || item.value;
                                    // item.value = lookup[item.value];
                                    return {
                                        ...item,
                                        highlighted: lookup[item.value] || item.value
                                    };
                                });
                            },
                            templates: {
                                showMoreText(data, { html }) {
                                    return html`<span class="btn btn-sm btn-primary">${data.isShowingMore ? 'Show less' : 'Show more'}</span>`;
                                },
                            },
                        }
                    )]);

            }
            // console.log(`Found div with data-attribute="${attribute}"`, div);
            // console.log(x);

            // You can now do something with each div individually
            // e.g., populate, modify, attach event listeners, etc.
        })




        // @todo: get the list of refinements.

        //   search.addWidgets([
        //       instantsearch.widgets.searchBox({
        //           container: '#searchbox',
        //       }),
        //       instantsearch.widgets.hits({
        //           container: '#hits',
        //           templates: {
        //               item: `
        //   <div>
        //     <div class="hit-name">
        //       {{#helpers.highlight}}{ "attribute": "name" }{{/helpers.highlight}}
        //     </div>
        //   </div>
        // `,
        //           },
        //       }),
        //   ])

        search.start();
    }

    // Add custom controller actions here
    // fooBar() { this.fooTarget.classList.toggle(this.bazClass) }

    disconnect() {
        // Called anytime its element is disconnected from the DOM
        // (on page change, when it's removed from or moved in the DOM, etc.)

        // Here you should remove all event listeners added in "connect()"
        // this.fooTarget.removeEventListener('click', this._fooBar)
    }

    /**
     *
     * Get the template specific to this index.
     *
     * @returns {Promise<void>}
     */
    async fetchFile() {
        console.assert(this.templateUrlValue);
        console.log('templateUrl' + this.templateUrlValue);
        try {
            const response = await fetch(this.templateUrlValue)
            if (!response.ok) {
                throw new Error(`HTTP ${this.templateUrlValue} ${response.status} – ${response.statusText}`)
            }

            // Decide how to read it (JSON vs. text)
            const contentType = response.headers.get("content-type") || ""
            let data
            if (contentType.includes("application/json")) {
                data = await response.json()
            } else {
                data = await response.text()
            }
            // this is inline loading
            this.template = Twig.twig({data: data});
            // this.template = tpl;

        } catch (error) {
            console.error("File fetch failed:", error)
            if (this.hasOutputTarget) {
                this.outputTarget.textContent = `Error loading file: ${error.message}`
            }
        }
    }

// 1) Create the custom widget factory
semanticRatioWidget({ container, min = 0, max = 1, step = 0.1, defaultValue = 0.5 ,searchController}) {
    let helper;
    const ctrl = this;
    return {
        init({ instantSearchInstance, helper: h }) {
            helper = h;

            // render the slider
            const root = document.querySelector(container);
            root.innerHTML = `
        <label>
          Semantic ratio:
          <input
            type="range"
            id="semantic-slider"
            min="${min}"
            max="${max}"
            step="${step}"
            value="${defaultValue}"
          />
          <span id="semantic-value">${defaultValue}</span>
        </label>
      `;

            // wire up the listener
            const slider = root.querySelector('#semantic-slider');
            const valueDisplay = root.querySelector('#semantic-value');
            console.log(slider);

            slider.addEventListener('input', () => {
                const ratio = parseFloat(slider.value);
                console.error(ratio, ctrl.embedderNameValue);

                // that.setMeiliSearchParams({
                //     showRankingScoreDetails: true,
                //     keepZeroFacets: true,
                //     hybrid: { embedder: 'openai_dummy_products', semanticRatio: ratio }
                // });

                    window.setMeiliSearchParams({
                        showRankingScoreDetails: true,
                        keepZeroFacets: true,
                        hybrid: { embedder: ctrl.embedderNameValue, semanticRatio: ratio }
                    });




                // 2) tell the helper to include your custom param
                helper
                    .setQueryParameter('showRankingScoreDetails', true)
                    .setQueryParameter('embedder', ctrl.embedderNameValue)
                    .setQueryParameter('semantic_ratio', ratio);
                console.log(helper);
                helper
                    .search();                   // 3) re-run the search

                window.searchClient.clearCache();
                window.search.helper.search();
                valueDisplay.textContent = ratio.toFixed(2);

            });
        },
        render() {
            console.log('render()');

            // we don’t need to re-render anything on each search
        }
    };
}

}
