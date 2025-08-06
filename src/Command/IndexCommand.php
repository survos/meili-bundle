<?php

namespace Survos\MeiliBundle\Command;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\ApiGrid\Api\Filter\MultiFieldSearchFilter;
//use Survos\ApiGrid\Service\DatatableService;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MeiliBundle\Service\DoctrinePrimaryKeyStreamer;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Alias;
//add AmqpTransport from Jwage
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;


#[AsCommand(
    name: 'meili:index',
    description: 'Index entities for use with meilisearch',
    help: <<< END

meili:index App\\Entity\\Task read the tasks from the database and index
meili:index App\\Dto\\Task data/tasks.json read the json file, map to task and index.
END
)]
class IndexCommand extends Command
{
    private SymfonyStyle $io; // to make global
    public function __construct(
        protected ParameterBagInterface                       $bag,
        protected EntityManagerInterface                      $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface                               $logger,
        private MeiliService                                  $meiliService,
        private SettingsService                               $settingsService,
        private NormalizerInterface                           $normalizer,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales=[],

    )
    {
        parent::__construct();
    }

    /**
     * @param array $settings
     * @return array
     */
    public function getFilterableAttributes(array $settings): array
    {
        return $this->settingsService->getFieldsWithAttribute($settings, 'browsable');
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument("Class name")] ?string $class = null,
        #[Argument("CSV/Json filename/url")] ?string $filename = null,
        #[Argument("filter class name")] string $filter='',
        #[Option("limit")] ?int $limit = null,
        #[Option("Don't actually update the settings")] ?bool $dry = null,
        #[Option("pk")] string $pk = 'id',
        #[Option("index name, defaults to prefix + class shortname")] ?string $name = null,
        #[Option("dump")] ?int $dump = null,
        #[Option("create/update settings ")] ?bool $updateSettings = null,
        #[Option("reset the meili index")] ?bool $reset = null,
        #[Option("fetch and index the documents")] ?bool $fetch = null,
        #[Option("wait until index is finished before exiting")] ?bool $wait = null,
        #[Option("batch-size for sending documents to meili", name: 'batch')] int $batchSize = 100,
    ): int
    {

        $fetch ??= true; // use no-fetch to simply update the settings.
        $filterArray = $filter ? Yaml::parse($filter) : null;
        if ($class && !class_exists($class)) {
            $class = "App\\Entity\\$class";
            //
//            if (class_exists(Alias::class)) {
//                $class = Alias::classFor('user');
//            }
        }
        $classes = [];

        // just the the meili managed indexes from meiliservice
        foreach ($this->meiliService->indexedEntities as $entityClass) {
            $this->logger->warning($entityClass);
            // https://abendstille.at/blog/?p=163
            if ($class && ($entityClass <> $class)) {
                continue;
            }

            // skip if no groups defined
            if (!$groups = $this->settingsService->getNormalizationGroups($entityClass)) {
//                    if ($input->ver) {
                $io->error("ERROR {$class}: no normalization groups for " . $entityClass);
                return Command::FAILURE;
//                    }

            }
            $classes[$entityClass] = $groups;
        }

        $this->io = $io;

        foreach ($classes as $class=>$groups) {
            $indexName = $this->meiliService->getPrefixedIndexName((new \ReflectionClass($class))->getShortName());
            $this->io->title($indexName);
            if ($reset) {
                // this deletes the index!
                if ($dry) {
                    $io->error("you cannot have both --reset and --dry");
                    return Command::FAILURE;
                }
                $this->meiliService->reset($indexName);
                $updateSettings = true;
            }

            if ($updateSettings) {
                // pk of meili  index might be different than doctrine pk, e.g. $imdbId
                $index = $this->meiliService->getIndex($indexName, $pk);
                $this->configureIndex($class, $indexName, $index, $dry);
            }

            // skip if no documents?  Obviously, docs could be added later, e.g. an Owner record after import
//            $task = $this->waitForTask($this->getMeiliClient()->createIndex($indexName, ['primaryKey' => Instance::DB_CODE_FIELD]));

            // pk of meili  index might be different than doctine pk, e.g. $imdbId
//            $index = $this->configureIndex($class, $indexName);
            $index = $this->meiliService->getOrCreateIndex($indexName, autoCreate: false);
            if (!$index) {
                $this->io->error("Index {$indexName} not found, run meili:settings to create");
                return Command::FAILURE;
            }

            if ($fetch) {
                // this needs to be dispatched so we can index large collections.
                $stats = $this->indexClass($class, $index,
                    batchSize: $batchSize, indexName: $indexName, groups: $groups,
                    limit: $limit??0,
                    filter: $filter ? $filterArray: null,
                    primaryKey: $index->getPrimaryKey(),
                    dump: $dump,
                    max: $limit,
                    pk: $pk,
                );

                $this->io->success($indexName . ' Document count:' .$stats['numberOfDocuments']);
                $this->meiliService->waitUntilFinished($index);
            }


            if ($this->io->isVeryVerbose()) {
                $stats = $index->stats();
                $this->io->title("$indexName stats");
                $this->io->write(json_encode($stats, JSON_PRETTY_PRINT));
            }

            if ($this->io->isVerbose()) {
                $this->io->title("$indexName settings");
                $this->io->write(json_encode($index->getSettings(), JSON_PRETTY_PRINT));
                // now what?
            }
            $this->io->success($this->getName() . ' ' . $class . ' finished indexing to ' . $indexName);

        }

        $this->io->success($this->getName() . ' complete.');
        return self::SUCCESS;

    }

    private function configureIndex(string $class, string $indexName, Indexes $index): Indexes
    {

//        $reflection = new \ReflectionClass($class);
//        $classAttributes = $reflection->getAttributes();
//        $filterAttributes = [];
//        $sortableAttributes = [];

        $settings = $this->settingsService->getSettingsFromAttributes($class);
        $idFields = $this->settingsService->getFieldsWithAttribute($settings, 'is_primary');
        $primaryKey = count($idFields) ? $idFields[0] : 'id';

        $localizedAttributes = [];
        foreach ($this->enabledLocales as $locale) {
            $localizedAttributes[] = ['locales' => [$locale],
                'attributePatterns' => [sprintf('_translations.%s.*',$locale)]];
        }

//        $index = $this->meiliService->getIndex($indexName, $primaryKey);
//        $index->updateSortableAttributes($this->datatableService->getFieldsWithAttribute($settings, 'sortable'));
//        $index->updateSettings(); // could do this in one call
        $filterable = $this->getFilterableAttributes($settings);
            $results = $index->updateSettings($debug = [
//                'searchFacets' => false, // search _within_ facets
                'localizedAttributes' => $localizedAttributes,
                'displayedAttributes' => ['*'],
                'filterableAttributes' => $filterable,
                'sortableAttributes' => $this->settingsService->getFieldsWithAttribute($settings, 'sortable'),
                "faceting" => [
                    "sortFacetValuesBy" => ["*" => "count"],
                    "maxValuesPerFacet" => $this->meiliService->getConfig()['maxValuesPerFacet']
                ],
            ]);
            $stats = $this->meiliService->waitUntilFinished($index);
//            dd($stats, $debug, $filterable, $index->getUid());
        return $index;
    }

    private function indexClass(string  $class,
                                Indexes $index,
                                int $batchSize,
                                ?string $indexName=null,
                                array $groups=[],
                                int $limit=0,
                                ?array $filter=[],
                                ?int $dump=null,
                                ?string $primaryKey=null,
                                ?int $max = null,
                                ?string $subdomain=null,
    ?string $pk = null
    ): array
    {
        // not great, but okay for now.  hard-code to dedicated meili queue
        $stamps = [
            //new TransportNamesStamp('meili')
            //use jwage/amqp-transport
            new AmqpStamp('meili'),
        ];
        $startingAt = 0;
        $records = [];
        $primaryKey ??= $index->getPrimaryKey();
        $count = 0;
        $streamer = new DoctrinePrimaryKeyStreamer($this->entityManager, $class);
        $generator = $streamer->stream($batchSize);


        //$stamps = [];

//        $connection = $this->entityManager->getConnection();
//        $sql = "SELECT $primaryKey FROM " . $this->entityManager->getClassMetadata($class)->getTableName();
//        if ($max) {
//            $sql .= " LIMIT $max";
//        }
//        $ids = $connection->fetchFirstColumn($sql);
        $approx = $this->meiliService->getApproxCount($class);
        $progressBar = new ProgressBar($this->io, $approx);
        $progressBar->start();
        $this->io->title($class);
        foreach ($generator as $chunk) {
            $progressBar->advance(count($chunk));
            $envelope = $this->messageBus->dispatch(
                new BatchIndexEntitiesMessage($class,
                    entityData: $chunk,
                    reload: true,
                    primaryKeyName: $primaryKey),
                $stamps
            );
            //dump($envelope);
            if ($max && ($progressBar->getProgress() >= $max)) {
                break;
            }
            // $batch is like [1, 2, 54, ...]
            // Process these IDs
        }
        $progressBar->finish();
        return [
            'numberOfDocuments' => $progressBar->getProgress()
        ];
        $this->showIndexSettings($index);
        // much less relevant, since the ids have been dispatched but not run.  We can show the difference though.
        if ($wait) {
            $this->meiliService->waitUntilFinished($index);
        }

        $ids = $this->em->createQueryBuilder()
            ->select('e.' . $primaryKey)
            ->from($class, 'e')
            ->getQuery()
            ->getScalarResult(); // Returns [['id' => 1], ['id' => 2], ['id' => 54]]

        $ids = array_column($ids, 'id'); // Convert to [1, 2, 54]
        //dd($ids, $approx);

        $qb = $this->entityManager->getRepository($class)->createQueryBuilder('e');
        $qb->select('e.' . $primaryKey . " as id");

        if ($filter) {
            foreach ($filter as $var => $val) {
                $qb->andWhere('e.' . $var . "= :$var")
                    ->setParameter($var, $val);
            }
//            $qb->andWhere($filter);
        }


        $total = (clone $qb)->select("count(e.{$primaryKey})")->getQuery()->getSingleScalarResult();
        $this->io->title("Indexing $class ($total records, batches of $batchSize) ");
        if (!$total) {
            return ['numberOfDocuments'=>0];
        }

        $query = $qb->getQuery();
        $progressBar = $this->getProcessBar($total);
        $progressBar->setMessage("Indexing $class ($total records, batches of $batchSize) ");

        do {
        if ($batchSize) {
            assert($count < $total, "count $count >= total $total");
            $query
                ->setFirstResult($startingAt)
                ->setMaxResults($batchSize);
//            $this->io->writeln("Fetching $startingAt ($batchSize)");
        }
        //dd($query->getResult());
        $results = $query->toIterable();
//        if (is_null($count)) {
//            // slow if not filtered!
//            $count = count(iterator_to_array($results, false));
//        }
//            $results = $qb->getQuery()->toIterable();
            $startingAt += $batchSize;
//            $count += count(iterator_to_array($results, false)); //??

        if ($subdomain) {
            assert($count == 1, "$count should be one for " . $subdomain);
        }

        // where should we get the id from?  ApiProperty(identifier: true)?  #[ORM\Id()]?  $rp?
        foreach ($results as $idx => $r) {
            $count++;
            // @todo: pass in groups?  Or configure within the class itself?
            // maybe these should come from the ApiPlatform normalizer.

            // we should probably index from the actual api calls.
            // for now, just match the groups in the normalization groups of the entity
//            $groups = ['rp', 'searchable', 'marking', 'translation', sprintf("%s.read", strtolower($indexName))];
//            $data = $this->normalizer->normalize($r, null, ['groups' => $groups]);
            // maybe use less memory this way?
            $data = $this->normalizer->normalize(clone $r, null, ['groups' => $groups]);
            unset($r);

            $data = SurvosUtils::removeNullsAndEmptyArrays($data);
            if ($pk) {
                $primaryKey = $pk;
                SurvosUtils::assertKeyExists($pk, $data);
                $data['id'] = $data[$pk];
            }
            if (!array_key_exists('rp', $data)) {

            }
//            assert(array_key_exists('rp', $data), "missing rp in $class\n\n" . join("\n", array_keys($data)));
//            if (!array_key_exists($primaryKey, $data)) {
//                $this->logger->error($msg = "No primary key $primaryKey for " . $class);
//                SurvosUtils::assertKeyExists($primaryKey, $data);
//                assert(false, $msg . "\n" . join("\n", array_keys($data)));
//                return ['numberOfDocuments'=>0];
//                break;
//            }
            $data['id'] = $data[$primaryKey]; // ??
            if (array_key_exists('keyedTranslations', $data)) {
                $data['_translations'] = $data['keyedTranslations'];
                $data['targetLocales'] = array_keys($data['_translations']);
//                unset($data['keyedTranslations']);
            }
            // @todo: use pk for dump index of index
            if ($dump && ($dump === ($idx+1))) {
                dd(data:    $data);
            }
//
            $records[] = $data;
//            if (count($data['tags']??[]) == 0) { continue; dd($data['tags'], $r->getTags()); }

            if ($batchSize && (($progress = $progressBar->getProgress()) % $batchSize) === 0) {
                $task = $index->addDocuments($records, $primaryKey);
                // wait for the first record, so we fail early and catch the error, e.g. meili down, no index, etc.
                if (!$progress) {
                    $this->meiliService->waitForTask($task);
                }
                $this->io->writeln("Flushing " . count($records));
                $records = [];
                $this->entityManager->clear();
                unset($records);
                $records = [];
                gc_collect_cycles();
            }
            $progressBar->advance();
            assert($count == $progressBar->getProgress(), "$count  <> " . $progressBar->getProgress());

            if ($limit && ($progressBar->getProgress() >= $limit)) {
                $count = $total; // hack for breaking out of loop
                break;
            }
        }
//            $this->io->writeln("$count of $total loaded, this batch:" . count($records));
        if ($startingAt > $total) {
    //            dump($count, $total, $startingAt);
        }
        } while ( ($count < $total)) ;

        $progressBar->finish();
        // if there are some that aren't batched...
            $this->io->writeln("Final Flush " . count($records));
            $task = $index->addDocuments($records, $primaryKey);
            // if debugging
            $this->meiliService->waitForTask($task);
        if (count($records)) {
        }



        $this->showIndexSettings($index);
        return $this->meiliService->waitUntilFinished($index);

    }

    private function getTranslationArray($entity, $accessor) {
        $rows = [];
        $updatedRow = [Instance::DB_CODE_FIELD => $entity->getCode()];
        foreach ($entity->getTranslations() as $translation) {
            foreach (Instance::TRANSLATABLE_FIELDS as $fieldName) {
                $translatedValue = $accessor->getValue($translation, $fieldName);
                $updatedRow['_translations'][$translation->getLocale()][$fieldName] = $translatedValue;
            }
        }

        return $updatedRow;
    }

    public function getProcessBar(int $total=0): ProgressBar
    {
        // https://jonczyk.me/2017/09/20/make-cool-progressbar-symfony-command/
        $progressBar = new ProgressBar($this->io, $total);
        if ($total) {
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% -- %message%');
        } else {
            $progressBar->setFormat(' %current% [%bar%] %elapsed:6s% %memory:6s% -- %message%');

        }
        return $progressBar;
    }

    public function showIndexSettings(Indexes $index)
    {
        if ($this->io->isVeryVerbose()) {
            $table=  new Table($this->io);
            $table->setHeaders(['Attributes','Values']);
            try {
                $settings = $index->getSettings();
                foreach ($settings as $var => $val) {
                    if (is_array($val)) {
                        $table->addRow([str_replace('Attributes', '', $var)
                            , join("\n", $val)]);
                    }
                }
            } catch (\Exception $exception) {
                // no settings if it doesn't exist
            }
            $table->render();;
            $this->io->writeln($index->getUid());
        }

    }


}
