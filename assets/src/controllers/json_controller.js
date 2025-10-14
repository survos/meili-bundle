// import {prettyPrintJson} from 'pretty-print-json';
// import 'pretty-print-json/dist/css/pretty-print-json.min.css';

/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://symfony.com/bundles/StimulusBundle/current/index.html#lazy-stimulus-controllers
*/
// /* stimulusFetch: 'lazy' */
import Dialog from "@stimulus-components/dialog"
import {Meilisearch} from "meilisearch";
import "@andypf/json-viewer"; // define <andypf-json-viewer> in this scope

export default class extends Dialog {
    static targets = ['content', 'title', 'body']
    static values = {
        serverUrl: String,
        serverApiKey: String,

        indexName: String,
        id: String,
        data: {
            type: String,
            default: '{}'
        }
    }


    connect() {
        super.connect();
        this.index = false;
        console.log(this.serverUrlValue, this.serverApiKeyValue);
        // this.data = JSON.parse(this.dataValue);
        // console.log(this.data);
    }

    setIndex() {
        if (!this.index) {
            try {
                const client = new Meilisearch({
                    host: this.serverUrlValue,
                    apiKey: this.serverApiKeyValue,
                });
                this.index = client.index(this.indexNameValue);
            } catch (e) {
                console.error(e);
                console.error(this.serverUrlValue, this.serverApiKeyValue);
            }
        }
        console.assert(this.index, "index not set");

    }


    initialize() {
        console.log("initialize from " + this.identifier);

        super.initialize()
        // Called once when the controller is first instantiated (per element)

        // Here you can initialize variables, create scoped callables for event
        // listeners, instantiate external libraries, etc.
        // this._fooBar = this.fooBar.bind(this)
    }


    // Add custom controller actions here
    // fooBar() { this.fooTarget.classList.toggle(this.bazClass) }

    disconnect() {
        super.disconnect();
        // Called anytime its element is disconnected from the DOM
        // (on page change, when it's removed from or moved in the DOM, etc.)

        // Here you should remove all event listeners added in "connect()"
        // this.fooTarget.removeEventListener('click', this._fooBar)
    }

    modal(e) {
        // we could move the index logic here, so we have have a title
        console.log({hitId: e.currentTarget.dataset.hitId});
        this.hitId = e.currentTarget.dataset.hitId;
        this.open();
    }


    // Function to override on open.
    open(e) {
        // this.contentTarget.innerHTML = this.idValue;
        this.setIndex(); // if it hasn't been set yet.
        // we could skip this if it's lazy-loaded?

        this.index.getDocument(this.hitId).then(
            hit => {
                // const obj = { a: null, b: 2, c: { d: null, e: 5 } };
                // this.titleTarget.innerHTML = hit.name; // @todo: generalize!

                const clean = this.cleanObject(hit);
                // console.log(clean);
// → { b: 2, c: { e: 5 } }

                // const html = prettyPrintJson.toHtml(clean);
                console.log(hit);
                // this.userStatusOutlets.forEach(outlet => {
                //     console.log(outlet);
                // })
                // this.modalTarget.innerHTML = '<pre>' + html + '</pre>';
                // probably better..
                // https://stimulus.hotwired.dev/reference/controllers#cross-controller-coordination-with-events

                // const trigger = new CustomEvent("update-modal", {
                //     detail: {
                //         content: hit
                //     }});
                // window.dispatchEvent(trigger);

                const jsonViewer = document.createElement("andypf-json-viewer")
                jsonViewer.id = "hit_" + this.hitId
                jsonViewer.expanded = 1
                jsonViewer.indent = 3
                jsonViewer.showDataTypes = false
                jsonViewer.theme = "monokai"
                jsonViewer.showToolbar = true
                jsonViewer.showSize = true
                jsonViewer.showCopy = true
                jsonViewer.expandIconType = "square"
                jsonViewer.data = clean

                console.log(jsonViewer, jsonViewer.data);
                this.contentTarget.innerHTML = "";
                this.contentTarget.appendChild(jsonViewer);

                // get the first div.

                // const oldEl = this.contentTarget.querySelector("div");
                // this.contentTarget.replaceChild(jsonViewer, oldEl);
                // this.contentTarget.appendChild(jsonViewer);

                // this.contentTarget.innerHTML = '<pre class="json-container">' + html + '</pre>';
                // this.contentTarget.innerHTML = '<andypf-json-viewer class="json-container">' + JSON.stringify(clean) + '</andypf-json-viewer>';
                this.titleTarget.innerHTML = '@todo: title';
                // this.contentTarget.innerHTML = html;
                super.open();
            }
        );


    }

    // Function to override on close.
    close() {
        super.close();
    }

    // Function to override on backdropClose.
    backdropClose() {
        super.backdropClose();
    }

    cleanObject(obj) {
        // not necessary if the object has been cleaned before sending to meili

        Object.entries(obj).forEach(([key, value]) => {
            if (value && typeof value === 'object') {
                // Recurse into objects and arrays
                this.cleanObject(value);
            }

            const isNull = value === null;
            const isEmptyArray = Array.isArray(value) && value.length === 0;
            const isEmptyObject =
                value &&
                typeof value === 'object' &&
                !Array.isArray(value) &&
                Object.keys(value).length === 0;

            if (isNull || isEmptyArray || isEmptyObject) {
                delete obj[key];
            }
        });
        return obj;
    }

}
