<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Contracts\TasksQuery;
use Meilisearch\Contracts\TasksResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Message\BatchRemoveEntitiesMessage;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Attribute\AsTwigFunction;

final class MeiliService
{
    /**
     * Base-keyed settings, regardless of origin (pixie, doctrine, etc).
     * @var array<string,array<string,mixed>> baseName => settings
     */
    private array $baseSettings = [];
    // deprecated?
    public array $settings { get => $this->baseSettings; }

    /**
     * Back-compat: per-class settings as provided by compiler pass.
     * @var array<class-string, array<string,array<string,mixed>>> class => [baseName => settings]
     */
    private readonly array $indexSettings;

    public function __construct(
        protected ParameterBagInterface $bag,
        private readonly SettingsService $settingsService,
        private readonly EntityManagerInterface $entityManager,
        private readonly NormalizerInterface $normalizer,

        private readonly IndexNameResolver $nameResolver,

        private ?string $meiliHost = 'http://localhost:7700',
        private ?string $adminKey = null,
        private ?string $searchKey = null, // public
        private array $config = [],
        private array $groupsByClass = [],
        private ?LoggerInterface $logger = null,
        private ?HttpClientInterface $symfonyHttpClient = null,
        protected ?ClientInterface $httpClient = null,

        private(set) readonly array $indexedEntities = [],

        array $indexSettings = [],
    ) {
        $this->indexSettings = $indexSettings;

        // Build a base-name settings map for fast lookup.
        foreach ($this->indexSettings as $class => $indexes) {
            foreach ($indexes as $baseName => $settings) {
                if (!is_array($settings)) {
                    continue;
                }
                $settings['baseName'] = $baseName;
                $settings['class'] = $settings['class'] ?? $class;
                $this->baseSettings[$baseName] = $settings;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Core config / feature flags
    // ---------------------------------------------------------------------

    public bool $isMultiLingual
    {
        get => $this->nameResolver->isMultiLingual();
    }

    public bool $passLocale
    {
        get => $this->nameResolver->passLocale();
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getHost(): ?string
    {
        return $this->meiliHost;
    }

    public function getAdminKey(): ?string
    {
        return $this->adminKey;
    }

    public function getPublicApiKey(): ?string
    {
        return $this->searchKey;
    }

    public function getPrefix(): ?string
    {
        // For now keep reading from config; prefix application is done in MeiliRegistry::uidFor via IndexNameResolver.
        return $this->config['meiliPrefix'] ?? null;
    }

    // ---------------------------------------------------------------------
    // Locale + naming (Pixie-first, bundle-generic)
    // ---------------------------------------------------------------------

    /**
     * Canonical locale policy resolver for a base index key.
     * Prefers registry metadata (locales.source/targets) and falls back to enabled locales.
     *
     * @return array{source:string, targets:string[], all:string[]}
     */
    public function resolveLocalesForBase(string $baseName, string $fallbackLocale): array
    {
        return $this->nameResolver->localesFor($baseName, $fallbackLocale);
    }

    /**
     * base + locale => raw (unprefixed)
     */
    public function rawForBase(string $baseName, ?string $locale): string
    {
        return $this->nameResolver->rawFor($baseName, $locale);
    }

    /**
     * base + locale => uid (prefix applied)
     */
    public function uidForBase(string $baseName, ?string $locale): string
    {
        return $this->nameResolver->uidFor($baseName, $locale);
    }

    /**
     * Back-compat alias for uidForBase().
     *
     * @deprecated use uidForBase() instead
     */
    public function localizedUid(string $baseName, ?string $locale): string
    {
        // Prefer Symfony's trigger_deprecation() when available, otherwise fall back to PHP's E_USER_DEPRECATED.
        if (\function_exists('trigger_deprecation')) {
            trigger_deprecation(
                'survos/meili-bundle',
                '2.0',
                'Method %s() is deprecated; use %s::uidForBase() instead.',
                __METHOD__,
                self::class
            );
        } else {
            @\trigger_error(
                sprintf('Method %s() is deprecated; use %s::uidForBase() instead.', __METHOD__, self::class),
                E_USER_DEPRECATED
            );
        }

        return $this->uidForBase($baseName, $locale);
    }

    /**
     * raw => uid (prefix applied)
     */
    public function uidForRaw(string $rawName): string
    {
        return $this->nameResolver->uidForRaw($rawName);
    }

    // ---------------------------------------------------------------------
    // Registry-backed settings access
    // ---------------------------------------------------------------------

    /**
     * Base-keyed settings (preferred).
     */
    public function getIndexSetting(string $baseName): ?array
    {
        return $this->baseSettings[$baseName] ?? null;
    }

    /**
     * Legacy name retained: this now returns base-keyed settings.
     * (Previously it was a prefixed-name lookup.)
     */
    public function getRawIndexSettings(): array
    {
        return $this->baseSettings;
    }

    public function getAllSettings(): array
    {
        return $this->baseSettings;
    }

    /**
     * For diagnostics and listener logic: class => [uid => settings].
     * Note: uid keys here are prefixed and locale-expanded only if caller requests that.
     */
    public function indexedByClass(): array
    {
        $out = [];
        foreach ($this->baseSettings as $baseName => $settings) {
            $class = (string)($settings['class'] ?? '');
            if ($class === '') {
                continue;
            }
            // Keep keyed by baseName; callers should compute uidForBase() when needed.
            $out[$class][$baseName] = $settings;
        }
        return $out;
    }

    /**
     * True if at least one index for this class is configured for auto-indexing.
     * Pixie uses autoIndex=false.
     */
    public function shouldAutoIndex(string $entityClass): bool
    {
        $settingsByIndex = $this->indexSettings[$entityClass] ?? [];
        foreach ($settingsByIndex as $cfg) {
            if (is_array($cfg) && (($cfg['autoIndex'] ?? true) === true)) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // Meilisearch client + endpoints
    // ---------------------------------------------------------------------

    public function getMeiliClient(?string $host = null, ?string $apiKey = null): Client
    {
        static $clients = [];

        $host ??= $this->meiliHost;
        $apiKey ??= $this->adminKey;

        $key = (string)$host . '|' . (string)$apiKey;
        if (!isset($clients[$key])) {
            $symfonyWithGzip = $this->symfonyHttpClient
                ? $this->symfonyHttpClient->withOptions([])
                : null;

            $psr18 = $symfonyWithGzip
                ? new SymfonyPsr18Client($symfonyWithGzip)
                : new SymfonyPsr18Client();

            $psr17Factory = new \Http\Discovery\Psr17Factory();

            $clients[$key] = new Client(
                $host ?? 'http://localhost:7700',
                $apiKey,
                $psr18,
                $psr17Factory
            );
        }

        return $clients[$key];
    }

    public function getIndexEndpoint(string $uid): Indexes
    {
        return $this->getMeiliClient()->index($uid);
    }

    /**
     * Get or create an index by UID (already prefixed).
     */
    public function getOrCreateIndex(string $uid, string $primaryKey = 'id', bool $autoCreate = true, bool $wait = false): Indexes
    {
        $client = $this->getMeiliClient();

        try {
            $client->getIndex($uid);
        } catch (ApiException $exception) {
            if ($exception->httpStatus === 404) {
                if ($autoCreate) {
                    $task = $client->createIndex($uid, ['primaryKey' => $primaryKey]);
                    if ($wait) {
                        $task->wait();
                    }
                }
            } else {
                throw $exception;
            }
        }

        return $client->index($uid);
    }

    /**
     * Ensure base+locale index exists and apply indexLanguages when possible.
     * Pixie should call this (via its own wrapper) using baseName and locale.
     */
    public function ensureBaseLocaleIndex(string $baseName, string $locale, string $primaryKey = 'id', bool $autoCreate = true): Indexes
    {
        $uid = $this->uidForBase($baseName, $locale);
        $idx = $this->getOrCreateIndex($uid, $primaryKey, $autoCreate);

        try {
            $idx->updateSettings(['indexLanguages' => [strtolower($locale)]]);
        } catch (\Throwable $e) {
            $this->logger?->debug('indexLanguages not supported', ['uid' => $uid, 'locale' => $locale]);
        }

        return $idx;
    }

    // ---------------------------------------------------------------------
    // Tasks / maintenance
    // ---------------------------------------------------------------------

    public function getTasks(?string $indexUid = null, array $statuses = [], array $types = [], int $limit = 100): TasksResults
    {
        $tasksQuery = new TasksQuery();

        if ($indexUid !== null) {
            $tasksQuery->setIndexUids([$indexUid]);
        }
        if ($types) {
            $tasksQuery->setTypes($types);
        }
        if ($limit) {
            $tasksQuery->setLimit($limit);
        }
        if ($statuses) {
            $tasksQuery->setStatuses($statuses);
        }

        return $this->getMeiliClient()->getTasks($tasksQuery);
    }

    public function reset(string $uid): void
    {
        try {
            $client = $this->getMeiliClient();
            $task = $client->deleteIndex($uid);
            $task->wait();
            $this->logger?->warning(sprintf('Index %s deleted.', $uid));
        } catch (ApiException $exception) {
            if (($exception->errorCode ?? null) === 'index_not_found' || $exception->httpStatus === 404) {
                return;
            }
            throw $exception;
        }
    }

    public function purge(string $uid): void
    {
        try {
            $client = $this->getMeiliClient();
            $index = $client->index($uid);
            $index->deleteAllDocuments();
            $client->deleteIndex($uid)->wait();
            $this->logger?->warning(sprintf('Index %s purged+deleted.', $uid));
        } catch (ApiException $exception) {
            if (($exception->errorCode ?? null) === 'index_not_found' || $exception->httpStatus === 404) {
                return;
            }
            throw $exception;
        }
    }

    /**
     * Fast list of server indexes (SDK objects), keyed by UID.
     * Useful for diagnostics.
     *
     * @return array<string,Indexes>
     */
    public function listIndexesFast(): array
    {
        $rows = [];
        foreach ($this->getMeiliClient()->getIndexes((new IndexesQuery())->setLimit(10000)) as $row) {
            if ($row instanceof Indexes) {
                $rows[$row->getUid()] = $row;
            }
        }
        return $rows;
    }

    // ---------------------------------------------------------------------
    // Doctrine legacy paths (keep for later compatibility pass)
    // ---------------------------------------------------------------------

    /**
     * Legacy: resolve an index UID for a Doctrine entity class + locale.
     * This keeps your previous "m_Short_es" behavior but routes through the new resolver.
     */
    public function resolveIndexUidForEntity(string $entityClass, ?string $locale = null, ?string $explicitBase = null): string
    {
        $base = $explicitBase ?: (new \ReflectionClass($entityClass))->getShortName();
        return $this->uidForBase($base, $locale);
    }

    private function getMeiliIndexForEntity(string $class, ?string $locale = null): Indexes
    {
        $uid = $this->resolveIndexUidForEntity($class, $locale);
        return $this->getIndexEndpoint($uid);
    }

    private function flushToMeili(Indexes $meiliIndex, array $documents): void
    {
        $count = count($documents);
        try {
            $task = $meiliIndex->addDocuments($documents);
            $this->logger?->debug(sprintf('MeiliSearch task %s created for %d documents', $task['taskUid'] ?? 'unknown', $count));
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('Failed to index %d documents: %s', $count, $e->getMessage()));
            throw $e;
        }
    }

    #[AsTwigFunction('approximate_count')]
    public function getApproxCount(string $class): ?int
    {
        static $counts = null;

        if (!class_exists($class)) {
            return -1;
        }

        try {
            $repo = $this->entityManager->getRepository($class);
        } catch (\Throwable) {
            return -2;
        }

        try {
            if ($counts === null) {
                $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                    "SELECT n.nspname AS schema_name,
       c.relname AS table_name,
       c.reltuples AS estimated_rows
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind = 'r'
  AND n.nspname NOT IN ('pg_catalog', 'information_schema')
ORDER BY n.nspname, c.relname;"
                );

                $counts = array_combine(
                    array_map(static fn($r) => (string)$r['table_name'], $rows),
                    array_map(static fn($r) => (int)$r['estimated_rows'], $rows)
                );
            }

            $table = $repo->getClassMetadata()->getTableName();
            $count = $counts[$table] ?? -1;
        } catch (\Throwable) {
            $count = -1;
        }

        if ($count < 0) {
            $count = $repo->count();
        }

        return $count;
    }

    #[AsMessageHandler]
    public function batchRemoveEntities(BatchRemoveEntitiesMessage $message): void
    {
        $meiliIndex = $this->getMeiliIndexForEntity($message->entityClass);

        try {
            $this->logger?->info(sprintf('Batch removing %d entities of class %s', count($message->entityIds), $message->entityClass));
            $meiliIndex->deleteDocuments($message->entityIds);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('Failed to batch remove %d entities of class %s: %s', count($message->entityIds), $message->entityClass, $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Doctrine indexing path; keep as-is for now (you said you will revisit later).
     */
    public function loadAndFlush(
        BatchIndexEntitiesMessage $message,
        EntityRepository $repo,
        string $identifierField,
        ?array $groups,
        int $payloadSize,
        array $documents,
        int $payloadThreshold,
        Indexes $meiliIndex
    ): void {
        $batchSize = 500;

        foreach (array_chunk($message->entityData, $batchSize) as $chunk) {
            $entities = $repo->findBy([$identifierField => $chunk]);

            foreach ($entities as $entity) {
                $normalized = $this->normalizer->normalize($entity, 'array', ['groups' => $groups]);
                $normalized = SurvosUtils::removeNullsAndEmptyArrays($normalized);

                $json = json_encode($normalized);
                $size = $json ? strlen($json) : 0;

                $payloadSize += $size;
                $documents[] = $normalized;

                if ($payloadSize >= $payloadThreshold) {
                    $this->flushToMeili($meiliIndex, $documents);
                    $documents = [];
                    $payloadSize = 0;
                    $this->entityManager->clear();
                    gc_collect_cycles();
                }
            }

            $this->entityManager->clear();
        }

        if ($documents) {
            $this->flushToMeili($meiliIndex, $documents);
        }
    }

    // ---------------------------------------------------------------------
    // Convenience: apply callback to documents in an index (UID)
    // ---------------------------------------------------------------------

    public function applyToIndex(string $uid, callable $callback, int $batch = 50): void
    {
        $index = $this->getMeiliClient()->index($uid);

        $documents = $index->getDocuments((new DocumentsQuery())->setLimit(0));
        $total = $documents->getTotal();

        $currentPosition = 0;
        while ($currentPosition < $total) {
            $documents = $index->getDocuments((new DocumentsQuery())->setOffset($currentPosition)->setLimit($batch));
            foreach ($documents->getIterator() as $row) {
                $callback($row, $index);
            }
            $currentPosition += $documents->count();
        }
    }

    /**
     * Summarize a list of index UIDs (exact names as on the Meili server).
     *
     * Dashboard-safe:
     * - Works even if an index does not exist (exists=false)
     * - Uses $client->getIndex($uid) for updatedAt/primaryKey (canonical SDK call)
     * - Uses $client->index($uid)->stats() for document count
     *
     * If you pass $uidToLocale, each row will include 'locale' for the given uid.
     *
     * @param list<string> $indexUids
     * @param array<string,string> $uidToLocale  uid => locale
     * @return array<int,array{
     *   indexName:string,
     *   locale:?string,
     *   exists:bool,
     *   documentCount:?int,
     *   updatedAt:?\DateTimeImmutable,
     *   primaryKey:?string,
     *   error:?string
     * }>
     */
    public function getIndexSummaries(array $indexUids, array $uidToLocale = []): array
    {
        $client = $this->getMeiliClient();

        $rows = [];
        foreach (array_values(array_unique($indexUids)) as $uid) {
            $row = [
                'indexName'     => $uid,
                'locale'        => $uidToLocale[$uid] ?? null,
                'exists'        => false,
                'documentCount' => null,
                'updatedAt'     => null,
                'primaryKey'    => null,
                'error'         => null,
            ];

            try {
                $info = $client->getIndex($uid); // array: uid, primaryKey, createdAt, updatedAt
                $row['exists'] = true;

                if (is_array($info)) {
                    $row['primaryKey'] = $info['primaryKey'] ?? null;

                    if (isset($info['updatedAt']) && is_string($info['updatedAt'])) {
                        $row['updatedAt'] = new \DateTimeImmutable($info['updatedAt']);
                    }
                }

                $stats = $client->index($uid)->stats();
                if (is_array($stats) && array_key_exists('numberOfDocuments', $stats)) {
                    $row['documentCount'] = (int) $stats['numberOfDocuments'];
                }
            } catch (\Meilisearch\Exceptions\ApiException $e) {
                if (($e->httpStatus ?? null) !== 404) {
                    $row['error'] = $e->getMessage();
                }
            } catch (\Throwable $e) {
                $row['error'] = $e->getMessage();
            }

            $rows[] = $row;
        }

        return $rows;
    }


}
