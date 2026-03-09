# Installation

## Requirements

- PHP 8.4+
- Symfony 7.4 or 8.0+
- A running Meilisearch instance (local Docker or [Meilisearch Cloud](https://www.meilisearch.com/cloud))

## 1. Start Meilisearch

The fastest way locally is Docker:

```bash
docker run -d \
  --name meilisearch \
  -p 7700:7700 \
  -e MEILI_MASTER_KEY=Y0urVery-S3cureAp1K3y \
  -v $(pwd)/meili_data:/meili_data \
  getmeili/meilisearch:latest
```

Optional: add the [riccox/meilisearch-ui](https://github.com/riccoxie/meilisearch-ui) dashboard:

```bash
docker run -d \
  --name meilisearch-ui \
  -e SINGLETON_MODE=true \
  -e SINGLETON_HOST=http://localhost:7700 \
  -e SINGLETON_API_KEY=Y0urVery-S3cureAp1K3y \
  -p 24900:24900 \
  riccoxie/meilisearch-ui:latest
```

Browse to `http://localhost:24900` to inspect indexes and documents.

## 2. Install the bundle

```bash
composer require survos/meili-bundle
```

The bundle also needs the Meilisearch PHP SDK and a PSR-7 implementation:

```bash
composer require meilisearch/meilisearch-php symfony/http-client nyholm/psr7
```

(These are pulled in automatically as transitive dependencies in most setups.)

## 3. Environment variables

Add to `.env.local` (never commit real keys to `.env`):

```dotenv
MEILI_SERVER=http://localhost:7700
MEILI_API_KEY=Y0urVery-S3cureAp1K3y
MEILI_SEARCH_KEY=your-public-search-only-key
```

Get your search-only key from the Meilisearch dashboard or via:

```bash
curl http://localhost:7700/keys \
  -H "Authorization: Bearer Y0urVery-S3cureAp1K3y" | jq '.results[] | select(.name == "Default Search API Key")'
```

## 4. Bundle configuration

Create `config/packages/survos_meili.yaml` (minimal):

```yaml
survos_meili:
    host:      '%env(default::MEILI_SERVER)%'
    apiKey:    '%env(default::MEILI_API_KEY)%'
    searchKey: '%env(default::MEILI_SEARCH_KEY)%'
```

See [Configuration](configuration.md) for the full reference.

## 5. Annotate an entity

```php
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MeiliBundle\Metadata\Facet;

#[MeiliIndex(
    primaryKey: 'id',
    searchable: ['title', 'description'],
    filterable: ['category', 'brand', 'price'],
    sortable: ['price', 'rating'],
)]
#[ORM\Entity]
class Product
{
    #[ORM\Id, ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column]
    #[Facet(label: 'Category')]
    public string $category;

    // ...
}
```

## 6. Create the index and populate it

```bash
# Push index settings (searchable/filterable/sortable fields) to Meilisearch
bin/console meili:settings:update --force

# Stream all existing rows into the index
bin/console meili:populate product --all
```

Your data is now searchable at `http://localhost:7700`.

## 7. (Optional) Frontend assets

For the InstantSearch UI component:

```bash
bin/console importmap:require @andypf/json-viewer
bin/console ux:icons:lock
```

In `assets/app.js`:

```js
import 'instantsearch.css/themes/algolia.min.css';
import 'flag-icons/css/flag-icons.min.css';
```

## Next steps

- [Configuration reference](configuration.md) — prefix, embedders, meili_settings tuning
- [Indexing attributes](indexing.md) — full `#[MeiliIndex]` options, `Fields`, `#[Facet]`
- [Semantic search](semantic-search.md) — add AI-powered vector search
- [Commands](commands.md) — full command reference
