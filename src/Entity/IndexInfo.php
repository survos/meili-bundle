<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Survos\MeiliBundle\Metadata\Facet;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Catalog of known Meilisearch indexes — one row per index UID.
 *
 * Populated by:
 *   bin/console meili:sync-indexes --from-server   (live server discovery)
 *   bin/console meili:sync-indexes                 (locally-configured indexes)
 *   bin/console meili:enrich-registry              (fills label/description from dataset.yaml)
 *
 * This entity is itself indexed in Meilisearch so you can search/chat across
 * all known collections without leaving the app.
 */
#[ORM\Entity(repositoryClass: IndexInfoRepository::class)]
#[MeiliIndex(
    primaryKey: 'indexName',
    searchable: ['label', 'description', 'aggregator', 'institution', 'indexName'],
    filterable: ['aggregator', 'institution', 'locale', 'country', 'documentCount', 'status'],
    sortable: ['documentCount', 'createdAt', 'updatedAt', 'indexName'],
    // Exclude computed booleans and internal fields from the index
    displayed: ['indexName', 'label', 'description', 'aggregator', 'institution',
                'country', 'locale', 'documentCount', 'status', 'settings',
                'primaryKey', 'createdAt', 'updatedAt', 'lastIndexed'],
    chats: ['meili_assistant'],
    ui: ['columns' => 3, 'layout' => 'neutral'],
)]
class IndexInfo
{
    #[ORM\Id]
    #[ORM\Column]
    public readonly string $indexName;

    #[ORM\Column(type: 'datetime', nullable: true)]
    public ?\DateTime $lastIndexed = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    public ?\DateTime $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    public ?\DateTime $updatedAt = null;

    #[ORM\Column(type: 'integer')]
    public int $documentCount = 0;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $settings = [];

    #[ORM\Column(nullable: true)]
    public ?string $taskId = null;

    #[ORM\Column()]
    public string $primaryKey;

    #[ORM\Column(nullable: true)]
    public ?string $batchId = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    public ?string $status = null; // queued, processing, succeeded, failed

    // --- Metadata enriched from dataset.yaml / 00_meta ---

    /** Human-readable collection name, e.g. "Hook & Hastings Co., Organ Factory Collection" */
    #[ORM\Column(nullable: true)]
    public ?string $label = null;

    /** Short description of the collection */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $description = null;

    /** Aggregator code: dc, pp, omeka, smith, euro, … */
    #[ORM\Column(nullable: true)]
    #[Facet(label: 'Aggregator', order: 1, lookup: [
        'dc'       => 'Digital Commonwealth',
        'pp'       => 'PastPerfect Online',
        'omeka'    => 'Omeka',
        'smith'    => 'Smithsonian',
        'euro'     => 'Europeana',
        'fortepan' => 'Fortepan',
        'ddb'      => 'Deutsche Digitale Bibliothek',
        'glam'     => 'Open GLAM Survey',
        'aac'      => 'American Art Collaborative',
        'md'       => 'museum-digital',
        'mds'      => 'Museum Data Service (MDS)',
    ])]
    public ?string $aggregator = null;

    /** Holding institution name */
    #[ORM\Column(nullable: true)]
    #[Facet(label: 'Institution', order: 2)]
    public ?string $institution = null;

    /** Country ISO2, e.g. "US", "GB", "DE" */
    #[ORM\Column(nullable: true)]
    #[Facet(label: 'Country', order: 3, lookup: [
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'DE' => 'Germany',
        'HU' => 'Hungary',
        'FR' => 'France',
        'NL' => 'Netherlands',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'PL' => 'Poland',
        'NZ' => 'New Zealand',
        'AU' => 'Australia',
        'CA' => 'Canada',
    ])]
    public ?string $country = null;

    /** Primary language of the collection content, e.g. "en", "de", "hu" */
    #[ORM\Column(nullable: true)]
    #[Facet(label: 'Language', order: 4, lookup: [
        'en' => 'English',
        'de' => 'German',
        'hu' => 'Hungarian',
        'fr' => 'French',
        'nl' => 'Dutch',
        'it' => 'Italian',
        'es' => 'Spanish',
        'pl' => 'Polish',
        'cs' => 'Czech',
        'sv' => 'Swedish',
        'fi' => 'Finnish',
        'da' => 'Danish',
        'no' => 'Norwegian',
        'pt' => 'Portuguese',
    ])]
    public ?string $locale = null;

    public function __construct(string $indexName, string $primaryKey, ?string $locale = null)
    {
        $this->indexName  = $indexName;
        $this->primaryKey = $primaryKey;
        $this->locale     = $locale;
    }

    public function isComplete(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['queued', 'processing']);
    }
}
