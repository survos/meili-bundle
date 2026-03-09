import '@symfony/stimulus-bundle';

console.log('meili.js loads bootstrap and algolia css');

import '@tabler/core';
import '@tabler/core/dist/css/tabler.min.css';
import 'instantsearch.css/themes/algolia.min.css';

// twig-browser must be in the importmap so chat.html.twig can dynamic-import it.
// Chat pages only load the 'meili' entrypoint (not meili_bootstrap/insta_controller).
import '@tacman1123/twig-browser';


