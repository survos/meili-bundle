<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Entity;

use Doctrine\DBAL\Types\Types;
use function in_array;
use function is_array;
use function is_string;
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
    private const REGISTRY_KEY = '_registry';
    private const CHAT_WORKSPACES_KEY = 'chatWorkspaces';
    private const SERVER_KEYS_KEY = 'serverKeys';

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

    /** @return array<string,mixed> */
    public function getChatWorkspaceAccess(string $workspace): array
    {
        $registry = $this->settings[self::REGISTRY_KEY] ?? null;
        if (!is_array($registry)) {
            return [];
        }

        $workspaces = $registry[self::CHAT_WORKSPACES_KEY] ?? null;
        if (!is_array($workspaces)) {
            return [];
        }

        $entry = $workspaces[$workspace] ?? null;

        return is_array($entry) ? $entry : [];
    }

    public function getChatWorkspaceApiKey(string $workspace): ?string
    {
        $apiKey = $this->getChatWorkspaceAccess($workspace)['apiKey'] ?? null;

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }

    public function getChatWorkspaceKeyUid(string $workspace): ?string
    {
        $keyUid = $this->getChatWorkspaceAccess($workspace)['keyUid'] ?? null;

        return is_string($keyUid) && $keyUid !== '' ? $keyUid : null;
    }

    public function setChatWorkspaceAccess(string $workspace, string $apiKey, string $keyUid): void
    {
        $registry = $this->settings[self::REGISTRY_KEY] ?? [];
        if (!is_array($registry)) {
            $registry = [];
        }

        $workspaces = $registry[self::CHAT_WORKSPACES_KEY] ?? [];
        if (!is_array($workspaces)) {
            $workspaces = [];
        }

        $workspaces[$workspace] = [
            'apiKey' => $apiKey,
            'keyUid' => $keyUid,
        ];

        $registry[self::CHAT_WORKSPACES_KEY] = $workspaces;
        $this->settings[self::REGISTRY_KEY] = $registry;
    }

    public function replaceSettingsPreservingRegistry(array $settings): void
    {
        $registry = $this->settings[self::REGISTRY_KEY] ?? null;
        $this->settings = $settings;

        if (is_array($registry) && $registry !== []) {
            $this->settings[self::REGISTRY_KEY] = $registry;
        }
    }

    /** @return array<string,mixed> */
    public function getServerKeyAccess(string $alias): array
    {
        $registry = $this->settings[self::REGISTRY_KEY] ?? null;
        if (!is_array($registry)) {
            return [];
        }

        $serverKeys = $registry[self::SERVER_KEYS_KEY] ?? null;
        if (!is_array($serverKeys)) {
            return [];
        }

        $entry = $serverKeys[$alias] ?? null;

        return is_array($entry) ? $entry : [];
    }

    public function getServerApiKey(string $alias): ?string
    {
        $apiKey = $this->getServerKeyAccess($alias)['apiKey'] ?? null;

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }

    public function getServerKeyUid(string $alias): ?string
    {
        $keyUid = $this->getServerKeyAccess($alias)['keyUid'] ?? null;

        return is_string($keyUid) && $keyUid !== '' ? $keyUid : null;
    }

    public function setServerKeyAccess(string $alias, string $apiKey, string $keyUid): void
    {
        $registry = $this->settings[self::REGISTRY_KEY] ?? [];
        if (!is_array($registry)) {
            $registry = [];
        }

        $serverKeys = $registry[self::SERVER_KEYS_KEY] ?? [];
        if (!is_array($serverKeys)) {
            $serverKeys = [];
        }

        $serverKeys[$alias] = [
            'apiKey' => $apiKey,
            'keyUid' => $keyUid,
        ];

        $registry[self::SERVER_KEYS_KEY] = $serverKeys;
        $this->settings[self::REGISTRY_KEY] = $registry;
    }
}
