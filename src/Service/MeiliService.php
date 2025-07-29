<?php

namespace Survos\MeiliBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Message\BatchRemoveEntitiesMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;

use function Symfony\Component\String\u;
use Symfony\Component\HttpClient\Psr18Client;
use Nyholm\Psr7\Factory\Psr17Factory;

class MeiliService
{
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
        private(set) readonly array $indexedEntities = []
    ) {
//        assert($this->meiliKey);
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

    public function getPublicApiKey(): ?string
    {
        return $this->searchKey; // @todo: 2 keys
    }


    public function reset(string $indexName)
    {
        $client = $this->getMeiliClient();
        try {
            $index = $client->index($indexName);
//            dd($index);
            $task = $client->deleteIndex($indexName);
            $this->waitForTask($task, $index);
//            $this->io()->info("Deletion Task is at " . $task['status']);
            $this->logger->warning("Index " . $indexName . " has been deleted.");
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


    public function waitForTask(array|string|int $taskId, ?Indexes $index = null, bool $stopOnError = true, mixed $dataToDump = null): array
    {

        if (is_array($taskId)) {
            $taskId = $taskId['taskUid'];
        }
        if ($index) {
            $task = $index->waitForTask($taskId);
        } else {
            // e.g index creation, when we don't have an index.  there's probably a better way.
            while (
                ($task = $this->getMeiliClient()->getTask($taskId))
                && (($status = $task['status']) && !in_array($status, ['failed', 'succeeded']))
            ) {
                if (isset($this->logger)) {
//                    $this->logger->info(sprintf("Task %s is at %s", $taskId, $status));
                }
//                $this->logg('sleeping');
                sleep(1);
//                usleep(50_000);
            };
            if ($status == 'failed') {
                if ($stopOnError) {
                    $this->logger?->warning(json_encode($dataToDump ?? [], JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES));
                    throw new \Exception("Task has failed " . $task['error']['message']);
                }
            }
        }

        return $task;
    }

    public function getPrefixedIndexName(string $indexName)
    {
        if (class_exists($indexName)) {
            $indexName = new \ReflectionClass($indexName)->getShortName();
        }
        if ($prefix = $this->getConfig()['meiliPrefix']) {
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
            dump($stats);
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
        $indexName = $this->getPrefixedIndexName($indexName);
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

    public function getOrCreateIndex(string $indexName, string $key = 'id', bool $autoCreate = true): ?Indexes
    {
        $client = $this->getMeiliClient();
        try {
            $index = $client->getIndex($indexName);
        } catch (ApiException $exception) {
            if ($exception->httpStatus === 404) {
                if ($autoCreate) {
                    $task = $this->waitForTask($this->getMeiliClient()->createIndex($indexName, ['primaryKey' => $key]));
            $this->getMeiliClient()->createIndex($indexName, ['primaryKey' => $key]);
                    $index = $client->getIndex($indexName);
                } else {
                    $index = null;
                }
            } else {
                dd($exception, $exception::class);
            }
        }
        return $index;
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



    private function getMeiliIndex(string $class): Indexes
    {
        $indexName = $this->getPrefixedIndexName(
            (new \ReflectionClass($class))->getShortName()
        );
        return $this->getIndex($indexName);
    }

    #[AsMessageHandler]
    public function batchIndexEntities(BatchIndexEntitiesMessage $message): void
    {
        $repo = $this->entityManager->getRepository($message->entityClass);
        $metadata = $this->entityManager->getClassMetadata($message->entityClass);
        $identifierField = $metadata->getSingleIdentifierFieldName(); // primary key field name

        $groups = $this->settingsService->getNormalizationGroups($message->entityClass);
        $objects = $repo->findBy([$identifierField => $message->entitiesData]);
        $data = $this->normalizer->normalize($objects, 'array', ['groups' => $groups]);
        $data = SurvosUtils::removeNullsAndEmptyArrays($data);

        $meiliIndex = $this->getMeiliIndex($message->entityClass);
        assert($identifierField == $meiliIndex->getPrimaryKey(), "Pk mismatch  $identifierField");

            $this->logger?->warning(sprintf(
                "Batch indexing %d entities of class %s in MeiliSearch",
                count($message->entitiesData),
                $message->entityClass
            ));

            $task = $meiliIndex->addDocuments($data);
//            $this->waitForTask($task);

            $this->logger?->debug(sprintf(
                "MeiliSearch batch index task %s created for %d %s entities",
                $task['taskUid'] ?? 'unknown',
                count($message->entitiesData),
                $message->entityClass
            ));

        try {
        } catch (\Exception $e) {
            $this->logger?->error(sprintf(
                "Failed to batch index %d entities of class %s: %s",
                count($message->entitiesData),
                $message->entityClass,
                $e->getMessage()
            ));

            throw $e;
        }
    }

    /** Duplicated from WorkflowHelperService!  Maybe need a doctrine helper?  Or extensions? */
    public function getApproxCount(string $class): ?int
    {
        static $counts = null;
        $repo = $this->entityManager->getRepository($class);

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

}
