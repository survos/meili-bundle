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

    }

    private function configureIndex(string $class, string $indexName, Indexes $index, ?bool $dry): Indexes
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
//                dump($settingsConfig);
            }
        dump($settingsConfig);
        return $index;
    }

}
