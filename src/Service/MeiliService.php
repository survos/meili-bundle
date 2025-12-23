<?php

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
use Meilisearch\Exceptions\JsonDecodingException;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Meili\MeiliTaskType;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Message\BatchRemoveEntitiesMessage;
use Survos\MeiliBundle\MessageHandler\BatchIndexEntitiesMessageHandler;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;

use Twig\Attribute\AsTwigFunction;
use Zenstruck\Bytes;
use function Symfony\Component\String\u;
use Symfony\Component\HttpClient\Psr18Client;
use Nyholm\Psr7\Factory\Psr17Factory;

final class MeiliService
{
    public array $settings = [];
    public array $rawSettings = [];
    public function __construct(
        protected ParameterBagInterface $bag,
        private SettingsService $settingsService,
        private EntityManagerInterface $entityManager,
        private NormalizerInterface $normalizer,
        private ?string                 $meiliHost='http://localhost:7700',
        private ?string                 $adminKey=null,
        private ?string                 $searchKey=null, // public!
        private array                   $config = [],
        private array                   $groupsByClass = [],
        private ?LoggerInterface        $logger = null,
        private ?HttpClientInterface $symfonyHttpClient=null,
        protected ?ClientInterface      $httpClient = null,
        private(set) readonly array $indexedEntities = [],
        private readonly array $indexSettings=[],

    ) {
        foreach ($this->indexSettings as $class => $indexes) {
            foreach ($indexes as $rawName => $settings) {

                $settings['rawName'] = $rawName;
                $settings['prefixedName'] = $this->getPrefix() . $rawName;
                $this->settings[$this->getPrefix() . $rawName] = $settings;
                $this->rawSettings[$rawName] = $settings;
            }
        }
//        dd($this->indexSettings, $this->getPrefix());
//        assert($this->meiliKey);
    }

    public string $isMultiLingual { get => $this->config['multiLingual'] ; }

    public function localizedUid(string $baseUid, string $locale): string
    {
        return "{$baseUid}_{$locale}";
    }


//    public function getAllIndexSettings(): array
//    {
//        $settingsWithActualIndexName = [];
//        foreach ($this->indexSettings as $indexName => $settings) {
//            $settingsWithActualIndexName[$this->getPrefix() . $indexName] = $settings;
//        }
//        return $settingsWithActualIndexName;
//    }

    // raw because no prefix
    public function getRawIndexSettings(): array
    {
        return $this->rawSettings;
    }

    public function getAllSettings(): array
    {
        return $this->rawSettings;
    }

    public function indexedByClass(): array
    {
        $response = [];
        foreach ($this->settings as $index=>$settings) {
            // we don't really need to repeat the settings, but this saves another lookup
            $response[$settings['class']][$index] = $settings;
        }
        return $response;

    }
    public function getRawIndexSetting(string $rawName): ?array
    {
        SurvosUtils::assertKeyExists($rawName, $this->rawSettings);
        return $this->rawSettings[$rawName] ?? null;
    }
    public function getIndexSetting(string $indexName): ?array
    {
        SurvosUtils::assertKeyExists($indexName, $this->settings);
        return $this->settings[$indexName] ?? null;
    }

    public function getAdminKey(): ?string { return $this->adminKey; }

    public function getConfig(): array
    {
        return $this->config;
    }

    public bool $passLocale { get => $this->config['passLocale'] ?? false; }
    public array $tools { get => $this->config['tools']; }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getHost(): ?string
    {
        return $this->meiliHost;
    }

    public function getPublicApiKey(): ?string
    {
        return $this->searchKey; // @todo: 2 keys
    }

    public function getTasks(?string $indexUid = null, array $statuses = [], array $types=[], int $limit=100): TasksResults
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

        if (count($statuses) > 0) {
            $tasksQuery->setStatuses($statuses);
        }
        return $this->getMeiliClient()->getTasks($tasksQuery);

    }

    /**
     * Convenience debug logger (namespaced). No-ops if logger is null.
     */
    private function dlog(string $msg, array $ctx = []): void
    {
        $this->logger?->debug('meili: ' . $msg, $ctx);
    }

    /**
     * Resolve a final index name for an entity + locale, honoring the bundle prefix.
     * If $explicit is given we use that (still prefixing if needed).
     * Otherwise we default to {Short}_{locale} (or just {Short} if $locale is null).
     */
    public function resolveIndexName(string $entityClass, ?string $locale = null, ?string $explicit = null): string
    {
        if ($explicit) {
            return $this->getPrefixedIndexName($explicit);
        }
        $short = (new \ReflectionClass($entityClass))->getShortName();
        $base  = $locale ? sprintf('%s_%s', $short, $locale) : $short;
        return $this->getPrefixedIndexName($base);
    }

    /**
     * Get or create the target index for an entity+locale (or explicit name),
     * and best-effort set its language for stemming/tokenization.
     */
    public function getOrCreateLocaleIndex(
        string $entityClass,
        ?string $locale,
        ?string $explicitIndexName,
        string $primaryKeyName = 'id',
        bool $autoCreate = true
    ): Indexes {
        $name  = $this->resolveIndexName($entityClass, $locale, $explicitIndexName);
        $index = $this->getOrCreateIndex($name, $primaryKeyName, $autoCreate);
        if ($locale) {
            try {
                $index->updateSettings(['indexLanguages' => [$locale]]);
            } catch (\Throwable $e) {
                $this->dlog('indexLanguages not supported on server', ['index' => $name, 'locale' => $locale]);
            }
        }
        return $index;
    }

    /**
     * Build an IndexPlan to keep params tidy across call sites.
     */
    public function makePlan(
        string $entityClass,
        ?string $locale,
        ?string $explicitIndexName,
        string $primaryKeyName = 'id',
        ?string $transport = null
    ): \Survos\MeiliBundle\Model\IndexPlan {
        $name = $this->resolveIndexName($entityClass, $locale, $explicitIndexName);
        return new \Survos\MeiliBundle\Model\IndexPlan(
            entityClass:     $entityClass,
            locale:          $locale,
            indexName:       $name,
            primaryKeyName:  $primaryKeyName,
            transport:       $transport
        );
    }

    /**
     * Dispatch a BatchIndexEntitiesMessage for a prepared plan and a set of ids.
     * Keeps message wiring in one place. Returns the created message.
     *
     * @param list<scalar> $ids
     */
    public function dispatchBatchForPlan(
        \Survos\MeiliBundle\Model\IndexPlan $plan,
        array $ids,
        bool $reload = true,
        ?\Symfony\Component\Messenger\MessageBusInterface $bus = null
    ): \Survos\MeiliBundle\Message\BatchIndexEntitiesMessage {
        $this->dlog('dispatch batch', [
            'index' => $plan->indexName,
            'locale'=> $plan->locale,
            'class' => $plan->entityClass,
            'count' => \count($ids),
        ]);

        $msg = new \Survos\MeiliBundle\Message\BatchIndexEntitiesMessage(
            entityClass:     $plan->entityClass,
            entityData:      $ids,
            reload:          $reload,
            transport:       $plan->transport,
            primaryKeyName:  $plan->primaryKeyName,
            locale:          $plan->locale,
            indexName:       $plan->indexName
        );

        if ($bus) {
            $stamps = [];
            if ($plan->transport) {
                $stamps[] = new \Symfony\Component\Messenger\Stamp\TransportNamesStamp($plan->transport);
            } else {
                $stamps[] = new \Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp('meili');
            }
            dd($msg);
            $bus->dispatch($msg, $stamps);
        }

        return $msg;
    }

    public function reset(string $indexName)
    {
        $client = $this->getMeiliClient();
        try {
            $index = $client->index($indexName);
//            dd($index);
            $task = $client->deleteIndex($indexName);
            $task = $task->wait();
            $this->logger->warning("Index " . $indexName . " has been deleted. " . $task->getStatus()->value);
        } catch (ApiException $exception) {
            if ($exception->errorCode == 'index_not_found') {
                try {
//                    $this->io()->info("Index $indexName does not exist.");
                } catch (\Exception) {
                    //
                }
//                    continue;
            } else {
                dd($exception);
            }
        }
    }

    public function purge(string $indexName)
    {
        $client = $this->getMeiliClient();
        try {
            $index = $client->index($indexName);
            $task = $index->deleteAllDocuments();
            $task = $client->deleteIndex($indexName);
            $task = $task->wait();
            $this->logger->warning("Index " . $indexName . " has been deleted. " . $task->getStatus()->value);
        } catch (ApiException $exception) {
            if ($exception->errorCode == 'index_not_found') {
                try {
//                    $this->io()->info("Index $indexName does not exist.");
                } catch (\Exception) {
                    //
                }
//                    continue;
            } else {
                dd($exception);
            }
        }
    }

    public function getRelated(array $facets, string $indexName, string $locale): array
    {
        $lookups = [];
        if (str_ends_with($indexName, '_obj'))
        {
            foreach ($facets as $facet) {
                if (!in_array($facet, ['type','cla','cat'])) {
                    continue;
                }
                $related = str_replace('_obj', '_' . $facet, $indexName);
                $index = $this->getIndexEndpoint($related);
                $docs = $index->getDocuments();
                foreach ($docs as $doc) {
                    $lookups[$facet][$doc['id']] = $doc['t'][$locale]['label'];
                }
            }
        }
        return $lookups;

    }

//    public function waitForTask(Task|array|int $taskLike, ?Indexes $index = null, bool $stopOnError = true, mixed $dataToDump = null): Task
//    {
//        assert($taskLike instanceof Task, "pass the Task object.");
//        // Normalize to Task + id
//        $task = match (true) {
//            $taskLike instanceof Task => $taskLike,
//            \is_array($taskLike)      => Task::fromArray($taskLike),
//            \is_int($taskLike)        => Task::fromArray(['taskUid' => $taskLike]),
//            default                   => throw new \InvalidArgumentException('Unsupported task input.'),
//        };
//
//        $taskId = $task->taskUid;
//
//        // If we already have its terminal state, return early
//        if ($task->finished) {
//            return $task;
//        }
//
//        // Path A: We have an Indexes instance (SDK helper)
//        if ($index instanceof Indexes) {
//            $arr = $index->waitForTask($taskId);
//            return $task->updateFromArray($arr);
//        }
//
//        // Path B: Poll the client directly (for index-creation & admin ops)
//        // NOTE: implement getMeiliClient()->getTask(int $uid): array in this service.
//        do {
//            $arr = $this->getMeiliClient()->getTask($taskId);
//            $task->updateFromArray($arr);
//
//            $this->logger?->info(sprintf('Task %d status: %s', $taskId, $task->status));
//
//            if (!$task->finished) {
//                sleep(1);
//            }
//        } while (!$task->finished);
//
//        if ($task->failed && $stopOnError) {
//            $this->logger?->warning(
//                \json_encode($dataToDump ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
//            );
//
//            $message = $task->error['message'] ?? 'Unknown Meilisearch task error.';
//            throw new \RuntimeException(sprintf('Task %d failed: %s', $taskId, $message));
//        }
//
//        return $task;
//    }

    public function getPrefix(): ?string
    {
        return $this->getConfig()['meiliPrefix']??null;
    }

    public function getPrefixedIndexName(string $indexName)
    {
        if (class_exists($indexName)) {
            $indexName = new \ReflectionClass($indexName)->getShortName();
        }
        if ($prefix = $this->getPrefix())
        {
            if (!str_starts_with($indexName, $prefix)) {
                $indexName = $prefix . $indexName;
            }
        }
        return $indexName;
    }

    /**
     * @param \Meilisearch\Endpoints\Indexes $index
     * @param SymfonyStyle $io
     * @param string|null $indexName
     * @return array
     */
    public function waitUntilFinished(Indexes $index): array
    {
        do {
            $index->fetchInfo();
//            $info = $index->fetchInfo();
            $stats = $index->stats();
            $isIndexing = $stats['isIndexing'];
            $indexName = $index->getUid();
            if ($this->logger) {
                $this->logger->info(sprintf(
                    "\n%s is %s with %d documents",
                    $indexName,
                    $isIndexing ? 'indexing' : 'ready',
                    $stats['numberOfDocuments']
                ));
            }
            if ($isIndexing) {
                sleep(1);
            }
        } while ($isIndexing);
        return $stats;
    }


    public function getMeiliClient(?string $host=null, ?string $apiKey=null): Client
    {
        // @handle multiple server/keys

        static $clients=[];
        $host ??= $this->meiliHost;
        $apiKey ??= $this->adminKey; // in php, it's usually the admin key

        if (!array_key_exists($key=$host.$apiKey, $clients)) {
            // 1) take the original, immutable client and grab a new instance with gzip enabled
            $symfonyWithGzip = $this->symfonyHttpClient->withOptions([
//                'headers'   => ['Accept-Encoding' => 'gzip'],
            ]);

            // 2) wrap _that_ instance as PSR-18
            $psr18  = new SymfonyPsr18Client($symfonyWithGzip);
            $psr17Factory = new \Http\Discovery\Psr17Factory();

            $client = new Client(
                $host??$this->meiliHost,
                    $apiKey??$this->adminKey,
                $psr18,                   // PSR-18 client
                $psr17Factory            // PSR-17 StreamFactoryInterface
            );
            $clients[$key] = $client;

        }
        return $clients[$key];
    }

    public function getIndex(string $indexName, string $key = 'id', bool $autoCreate = true): ?Indexes
    {
//        $indexName = $this->getPrefixedIndexName($indexName);
        $this->loadExistingIndexes();
        static $indexes = [];
        if (!$index = $indexes[$indexName] ?? null) {
            if ($autoCreate) {
                $index = $this->getOrCreateIndex($indexName, $key);
                $indexes[$indexName] = $index;
            }
        }
        return $index;
    }

    /**
     * Ultra-fast list using raw HTTP GET {host}/indexes.
     * Returns lightweight info (uid, primaryKey, createdAt, updatedAt).
     * No pagination: Meilisearch returns all indexes on this endpoint.
     *
     * @return array<int,array{
     *   uid:string,
     *   primaryKey:?string,
     *   createdAt:?string,
     *   updatedAt:?string
     * }>
     */
    public function listIndexesFast(): array
    {
        $url = rtrim((string) $this->meiliHost, '/') . '/indexes';

        $headers = [];
        if ($this->adminKey) {
            // Meilisearch accepts Authorization: Bearer <key>
            $headers['Authorization'] = 'Bearer ' . $this->adminKey;
        }
//        dd($this->getMeiliClient()->getIndexes());
//
////        $x = $this->getMeiliClient()->http->get($url, $headers);
//        try {
//            $response = $this->getMeiliClient()->http->get($url, [
//                'headers' => $headers,
//                'timeout' => 10,
//            ]);
//        } catch (ApiException $e) {
//
//        } catch (JsonDecodingException $e) {
//
//        }
//        dd($response);
//
        // Expect an array of index rows; keep only the fields we need
//        dd($this->getMeiliClient()->getIndexes());

        $rows = [];
        /** @var Indexes $row */
        foreach ($this->getMeiliClient()->getIndexes(new IndexesQuery()->setLimit(10000)) as $uid=> $row) {
//            dd($row->getUid(), $row, $row::class, get_class_methods($row));
            $uid = $row->getUid();
            $rows[$uid] = $row;
//            [
//                'uid'        => (string)($row['uid'] ?? ''),
//                'primaryKey' => $row['primaryKey'] ?? null,
//                'createdAt'  => $row['createdAt'] ?? null, // ISO 8601 string (server time)
//                'updatedAt'  => $row['updatedAt'] ?? null, // ISO 8601 string (server time)
//            ];
        }

        return $rows;
    }

    public function getIndexEndpoint(string $indexName): Indexes
    {
        return $this->getMeiliClient()->index($indexName);

    }

    public function loadExistingIndexes()
    {
        return;
        $client = $this->getMeiliClient();
        do {
            $indexes = $client->getIndexes();
            dd($indexes);
        } while ($nextPage);
    }

    public function getOrCreateIndex(string $indexName, string $key = 'id', bool $autoCreate = true, bool $wait=false): Indexes
    {
        $client = $this->getMeiliClient();
        try {
            // by doing a fetch, we can see if it already exists, and dispatch a create request if not
            $index = $client->getIndex($indexName);
        } catch (ApiException $exception) {
            if ($exception->httpStatus === 404) {
                if ($autoCreate) {
                    // this dispatches a task, may not run here.
                    $task = $this->getMeiliClient()->createIndex($indexName, ['primaryKey' => $key]);
                    if ($wait) {
                        $task = $task->wait();
                    }
//                    $this->waitForTask($task);
                    // the API Endpoint
//                    $index = $client->index($indexName);
                } else {
                    $index = null;
                }
            } else {
                dd($exception, $exception::class);
            }
        }
        // return the endpoint, not the fetch
        return $client->index($indexName);
    }


    public function applyToIndex(string $indexName, callable $callback, int $batch = 50)
    {
        $index = $this->getMeiliClient()->index($indexName);

        $documents = $index->getDocuments((new DocumentsQuery())->setLimit(0));
        $total = $documents->getTotal();
        $currentPosition = 0;
        // dispatch MeiliRowEvents?
//        $progressBar = $this->getProcessBar($total);

        while ($currentPosition < $total) {
            $documents = $index->getDocuments((new DocumentsQuery())->setOffset($currentPosition)->setLimit($batch));
            $currentPosition += $documents->count();
            foreach ($documents->getIterator() as $row) {
//                $progressBar->advance();
                $callback($row, $index);
            }
            $currentPosition++;
        }
//        $progressBar->finish();
    }



    private function getMeiliIndex(string $class, ?string $locale=null): Indexes
    {
        $short     = (new \ReflectionClass($class))->getShortName();
        $base      = $locale ? sprintf('%s_%s', $short, $locale) : $short;
        $indexName = $this->getPrefixedIndexName($base);
        return $this->getIndex($indexName);
    }

    private function flushToMeili($meiliIndex, array $documents): void
    {
        $count = count($documents);
        try {
            $task = $meiliIndex->addDocuments($documents);
            $this->logger?->debug(sprintf(
                "MeiliSearch task %s created for %d documents",
                $task['taskUid'] ?? 'unknown',
                $count
            ));
        } catch (\Exception $e) {
            $this->logger?->error(sprintf(
                "Failed to index %d documents: %s",
                $count,
                $e->getMessage()
            ));
            throw $e;
        }
    }

    #[AsTwigFunction('approximate_count')]
    /** Duplicated from WorkflowHelperService!  Maybe need a doctrine helper?  Or extensions? */
    public function getApproxCount(string $class): ?int
    {
        static $counts = null;

        if (!class_exists($class)) {
            return -1;
        }
        try {
            $repo = $this->entityManager->getRepository($class);
        } catch (\Exception $e) {
            return -2;
        }
        try {
            if (is_null($counts)) {
                $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                    "SELECT n.nspname AS schema_name,
       c.relname AS table_name,
       c.reltuples AS estimated_rows
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind = 'r'
  AND n.nspname NOT IN ('pg_catalog', 'information_schema')  -- exclude system schemas
ORDER BY n.nspname, c.relname;");

                $counts = array_combine(
                    array_map(fn($r) => "{$r['table_name']}", $rows),
                    array_map(fn($r) => (int)$r['estimated_rows'], $rows)
                );
            }
            $count = $counts[$repo->getClassMetadata()->getTableName()] ?? -1;

//            // might be sqlite
//            $count =  (int) $this->getEntityManager()->getConnection()->fetchOne(
//                'SELECT reltuples::BIGINT FROM pg_class WHERE relname = :table',
//                ['table' => $this->getClassMetadata()->getTableName()]
//            );
        } catch (\Exception $e) {
            $count = -1;
        }

        // if no analysis
        // Fallback to exact count
        if ($count < 0) {
            $count = $repo->count();
//            // or $repo->count[]
//            $count = (int)$repo->createQueryBuilder('e')
//                ->select('COUNT(e)')
//                ->getQuery()
//                ->getSingleScalarResult();
        }

        return $count;
    }

    #[AsMessageHandler]
    public function batchRemoveEntities(BatchRemoveEntitiesMessage $message): void
    {
        try {
            $meiliIndex = $this->getMeiliIndex($message->entityClass);

            $this->logger?->info(sprintf(
                "Batch removing %d entities of class %s from MeiliSearch",
                count($message->entityIds),
                $message->entityClass
            ));

            $task = $meiliIndex->deleteDocuments($message->entityIds);

            $this->logger?->debug(sprintf(
                "MeiliSearch batch delete task %s created for %d %s entities",
                $task['taskUid'] ?? 'unknown',
                count($message->entityIds),
                $message->entityClass
            ));

        } catch (\Exception $e) {
            $this->logger?->error(sprintf(
                "Failed to batch remove %d entities of class %s: %s",
                count($message->entityIds),
                $message->entityClass,
                $e->getMessage()
            ));

            throw $e;
        }
    }

    /**
     * @param BatchIndexEntitiesMessage $message
     * @param \Doctrine\ORM\EntityRepository $repo
     * @param string $identifierField
     * @param array|null $groups
     * @param int $payloadSize
     * @param array $documents
     * @param int $payloadThreshold
     * @param Indexes $meiliIndex
     * @return void
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     */
    public function loadAndFlush(BatchIndexEntitiesMessage $message,
                                 EntityRepository $repo,
                                 string $identifierField,
                                 ?array $groups,
                                 int $payloadSize,
                                 array $documents,
                                 int $payloadThreshold,
                                 Indexes $meiliIndex): void
    {
        $batchSize = 500;

        foreach (array_chunk($message->entityData, $batchSize) as $chunk) {
            $entities = $repo->findBy([$identifierField => $chunk]);

            foreach ($entities as $entity) {
                dd($this->indexSettings, $message);
                $normalized = $this->normalizer->normalize($entity, 'array', ['groups' => $groups]);
                $normalized = SurvosUtils::removeNullsAndEmptyArrays($normalized);

                $json = json_encode($normalized);
                $size = $json ? strlen($json) : 0;
                $payloadSize += $size;
                $documents[] = $normalized;

                if ($payloadSize >= $payloadThreshold) {
                    $this->flushToMeili($meiliIndex, $documents, count($documents));
                    $documents = [];
                    $payloadSize = 0;
                    $this->entityManager->clear();
                    gc_collect_cycles();
                }
            }
            $this->entityManager->clear(); // clear batch
        }

        if (!empty($documents)) {
            $this->flushToMeili($meiliIndex, $documents, count($documents));
        }
    }

    public function resolveUid(string $baseIndexName, ?string $locale): string
    {
        if (!$this->isMultiLingual || !$this->passLocale || !$locale) {
            return $baseIndexName;
        }

        return $this->localizedUid($baseIndexName, $locale);
    }

}

