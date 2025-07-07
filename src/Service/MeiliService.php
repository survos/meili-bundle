<?php

// really this should extend, use or decorate MeiliService in MeiliBundle

namespace Survos\MeiliBundle\Service;

use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

class MeiliService
{
    public function __construct(
        protected ParameterBagInterface $bag,
        private ?string $meiliHost='http://localhost:7700',
        private ?string $meiliKey=null,
        private array $config = [],
        private array $groupsByClass = [],
        private ?LoggerInterface $logger = null,
        protected ?ClientInterface $httpClient = null,
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


    public function reset(string $indexName)
    {
        $client = $this->getMeiliClient();
        try {
            $index = $client->getIndex($indexName);
            $response = $client->deleteIndex($indexName);
            $task = $this->waitForTask($response['taskUid'], $index);
//            $this->io()->info("Deletion Task is at " . $task['status']);
            $this->logger->info("Index " . $indexName . " has been deleted.");
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
                usleep(50_000);
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
            $info = $index->fetchInfo();
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
        static $client;
        if (!$client)
        {
            $client = new Client(
                $host??$this->meiliHost, $apiKey??$this->meiliKey,
                httpClient: $this->httpClient);
        }
        return $client;
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
}
