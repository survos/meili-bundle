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
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Alias;

#[AsCommand(
    name: 'meili:settings',
    description: 'view and set meilisearch settings',
)]
class SettingsCommand # extends Command
{
    private SymfonyStyle $io;
    public function __construct(
        protected ParameterBagInterface                       $bag,
        protected EntityManagerInterface                      $entityManager,
        private LoggerInterface                               $logger,
        private MeiliService                                  $meiliService,
        private SettingsService                               $settingsService,
        private NormalizerInterface                           $normalizer,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales=[],
        #[Autowire('%env(OPENAI_API_KEY)%')] private string $openAiApiKey,
    )
    {
//        parent::__construct();
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
        #[Argument("filter class name")] string $filter='',
        #[Option("pk")] string $pk = 'id',
        #[Option("reset the meili index")] ?bool $reset = null,
        #[Option("Don't actually update the settings")] ?bool $dry = null,
    ): int
    {
        // if !class, prompt for possible classes

        if ($class && !class_exists($class)) {
            $class = "App\\Entity\\$class";
        }

        $indexName = $this->meiliService->getPrefixedIndexName((new \ReflectionClass($class))->getShortName());

        $io->title($indexName);
        if ($reset) {
            // this deletes the index!
            if ($dry) {
                $io->error("you cannot have both --reset and --dry");
                return Command::FAILURE;
            }
            $this->meiliService->reset($indexName);
        }

        // skip if no documents?  Obviously, docs could be added later, e.g. an Owner record after import
//            $task = $this->waitForTask($this->getMeiliClient()->createIndex($indexName, ['primaryKey' => Instance::DB_CODE_FIELD]));

        // pk of meili  index might be different than doctrine pk, e.g. $imdbId
        $index = $this->meiliService->getIndex($indexName, $pk);
        $index = $this->configureIndex($class, $indexName, $index, $dry);

        $stats = $index->stats();



        $io->title("$indexName stats");
        $io->write(json_encode($stats, JSON_PRETTY_PRINT));

        return Command::SUCCESS;

        // https://abendstille.at/blog/?p=163
            $metas = $this->entityManager->getMetadataFactory()->getAllMetadata();
            foreach ($metas as $meta) {
                // check argument
                if ($class && ($meta->getName() <> $class)) {
                    continue;
                }

                // skip if no groups defined
                if (!$groups = $this->settingsService->getNormalizationGroups($meta->getName())) {
//                    if ($input->ver) {
                        $io->writeln("Skipping {$class}: no normalization groups for " . $meta->getName());
//                    }
                    continue;
                }

                $classes[$meta->getName()] = $groups;
            }

        $this->io = $io;

        foreach ($classes as $class=>$groups) {
            $indexName = $this->meiliService->getPrefixedIndexName((new \ReflectionClass($class))->getShortName());
            $this->io->title($indexName);
            if ($reset) {
                $this->meiliService->reset($indexName);
            }

            // skip if no documents?  Obviously, docs could be added later, e.g. an Owner record after import
//            $task = $this->waitForTask($this->getMeiliClient()->createIndex($indexName, ['primaryKey' => Instance::DB_CODE_FIELD]));

            // pk of meili  index might be different than doctine pk, e.g. $imdbId
            $index = $this->configureIndex($class, $indexName);

            $this->io->success($indexName . ' Document count:' .$stats['numberOfDocuments']);
            $this->meiliService->waitUntilFinished($index);

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

    private function configureIndex(string $class, string $indexName, Indexes $index, bool $dry): Indexes
    {

//        $reflection = new \ReflectionClass($class);
//        $classAttributes = $reflection->getAttributes();
//        $filterAttributes = [];
//        $sortableAttributes = [];

        $settings = $this->settingsService->getSettingsFromAttributes($class);
        $idFields = $this->settingsService->getFieldsWithAttribute($settings, 'is_primary');
        $primaryKey = count($idFields) ? $idFields[0] : 'id';

        if ($indexName === 'dtdemo_Instrument') {
            $documentTemplate = 'Instrument {{ doc.name }} is of type {{ doc.type }}. {{ doc.description }}.
        {% if doc.genres %}
         {% assign genres = doc.genres|default: ""|split: "," %}
         Genres: {% for genre in genres %} 
         {{ genre }}{% if forloop.last %} and {% endif %}
         {% endfor %}
         {% endif %}
        ';
            $embedders = $index->getEmbedders();
            $embedder = [
                'open_ai_small' => [
                    'source' => 'openAi',
                    'model' => 'text-embedding-3-small',
                    'apiKey' => $this->openAiApiKey,
                    'documentTemplate' => $documentTemplate,
                ]
            ];
            $task = $index->updateEmbedders(
                $embedder,
            );
            $results = $this->meiliService->waitForTask($task);

            if ($results['status'] <> 'succeeded') {
                dd($results);
            }
        }
        $localizedAttributes = [];
        foreach ($this->enabledLocales as $locale) {
            $localizedAttributes[] = ['locales' => [$locale],
                'attributePatterns' => [sprintf('_translations.%s.*',$locale)]];
        }

        $index = $this->meiliService->getIndex($indexName, $primaryKey);
//        $index->updateSortableAttributes($this->datatableService->getFieldsWithAttribute($settings, 'sortable'));
//        $index->updateSettings(); // could do this in one call
        $filterable = $this->getFilterableAttributes($settings);
            $settingsConfig = [
//                'searchFacets' => false, // search _within_ facets
                'localizedAttributes' => $localizedAttributes,
                'displayedAttributes' => ['*'],
                'filterableAttributes' => $filterable,
                'sortableAttributes' => $this->settingsService->getFieldsWithAttribute($settings, 'sortable'),
                "faceting" => [
                    "sortFacetValuesBy" => ["*" => "count"],
                    "maxValuesPerFacet" => $this->meiliService->getConfig()['maxValuesPerFacet']
                ],
            ];
            if (!$dry) {
                $results = $index->updateSettings($settingsConfig);
//            $stats = $this->meiliService->waitUntilFinished($index);
//            dump($stats, $debug, $filterable, $index->getUid());
//        dump($index->getSettings(), $index->getEmbedders());
            } else {
                dump($settingsConfig);
            }
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
                                ?string $subdomain=null,
    ?string $pk = null
    ): array
    {
        $startingAt = 0;
        $records = [];
        $primaryKey ??= $index->getPrimaryKey();
        $count = 0;
        $qb = $this->entityManager->getRepository($class)->createQueryBuilder('e');

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
            $data = $this->normalizer->normalize($r, null, ['groups' => $groups]);
            $data = SurvosUtils::removeNullsAndEmptyArrays($data);
            if ($pk) {
                $primaryKey = $pk;
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
//            assert(array_key_exists('_translations', $data), "Missing translations for " .$r::class);
            // if live, only add if indexed and meiliCount
            // total hack, this doesn't belong in the indexer, but in the deserializer before sending the results back,
            // so somewhere in meili?
//            assert($data['locale'], "Missing locale for $class " . $code);
//            if ($projectLocale = $data['projectLocale']??$data['locale']??false) {
//                $language = Languages::getName($projectLocale);
//                $data['language'] = $language;
//            }

            // @todo: use pk for dump
            if ($dump && ($dump === ($idx+1))) {
                dd($data);
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
//                $this->io->writeln("Flushing " . count($records));
                $records = [];
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
