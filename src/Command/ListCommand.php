<?php

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Helper\TreeHelper;
use Symfony\Component\Console\Helper\TreeNode;
use Symfony\Component\Console\Style\SymfonyStyle;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\ApiGrid\Api\Filter\MultiFieldSearchFilter;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Alias;

#[AsCommand(
    name: 'meili:list',
    description: 'list indexes',
)]
class ListCommand extends Command
{
    private SymfonyStyle $io;
    public function __construct(
        private MeiliService $meiliService,
        private SettingsService $settingsService,
        protected ParameterBagInterface $bag,
        private LoggerInterface $logger,
        private NormalizerInterface $normalizer,
        protected ?EntityManagerInterface $entityManager=null,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales=[],

    )
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument] ?string $uId=null,
        #[Option] ?string $host=null,
        #[Option] ?string $apiKey=null,
    )
    {
        $client = $this->meiliService->getMeiliClient($host, $apiKey);

        if ($uId === null) {
            // @todo: make this an "ask", but list size?
            $io->title('List indexes');
            /** @var Indexes $index */
            foreach ($client->getIndexes() as $index) {
                $io->writeln(sprintf('<info>%s</info>', $index->getUid()));
            }
            return;
        }

        dump($host, $apiKey);
        $index = $client->index($uId);
//        dd($index->getUid());
        $results = $index->rawSearch('test');
//        dd($results);
        $settings = $index->getSettings();
        dd($settings);

        dd($index->fetchRawInfo());

        $settings = $index->getSettings();
        foreach ($settings as $var => $val) {
            if (is_object($val)) {
                $settings[$var] = (array)$val->jsonSerialize();
            }
            if ($val==null) {
//                $settings[$var] = 'null';
            }
        }
        $io->writeln(json_encode($settings, JSON_PRETTY_PRINT));
//        dd($settings);

//        $tree = TreeHelper::createTree($io, null, $settings);
//        $tree->render();
        dd();

        $tree = TreeHelper::createTree($io, null, [
            'src' =>  [
                'Command',
                'Controller' => [
                    'DefaultController.php',
                ],
                'Kernel.php',
            ],
            'templates' => [
                'base.html.twig',
            ],
        ]);

        $tree->render();
        dd();

        if ($uId === null) {
            // @todo: make this an "ask", but list size?
            $io->title('List indexes');
            $client = $this->meiliService->getMeiliClient();
            /** @var Indexes $index */
            foreach ($client->getIndexes() as $index) {
                $io->writeln(sprintf('<info>%s</info>', $index->getUid()));
            }
        }
        $index = $this->meiliService->getIndex($uId);
//        $node = TreeNode::fromValues($index->getSettings());
        $tree = TreeHelper::createTree($io, null, $index->getSettings());
        $tree->render();
    }

//    protected function configure(): void
//    {
//        $this
//            ->addArgument('class', InputArgument::OPTIONAL, 'Class to index', null)
//            ->addOption('reset', null, InputOption::VALUE_NONE, 'Reset the indexes')
//            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size to meili', 100)
//            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'limit', 0)
//            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'filter in yaml format')
//            ->addOption('dump', null, InputOption::VALUE_REQUIRED, 'dump the nth item', 0)
//        ;
//    }

    protected function XXexecute(InputInterface $input, OutputInterface $output): int
    {

        $filter = $input->getOption('filter');
        $filterArray = $filter ? Yaml::parse($filter) : null;
        $class = $input->getArgument('class');
        if (!class_exists($class)) {
            if (class_exists(Alias::class)) {
                $class = Alias::classFor('user');
            }
        }
            $classes = [];


            // https://abendstille.at/blog/?p=163
            $metas = $this->entityManager->getMetadataFactory()->getAllMetadata();
            foreach ($metas as $meta) {
                // check argument
                if ($class && ($meta->getName() <> $class)) {
                    continue;
                }

                // skip if no groups defined
                if (!$groups = $this->meiliService->getNormalizationGroups($meta->getName())) {
//                    if ($input->ver) {
                        $output->writeln("Skipping {$class}: no normalization groups for " . $meta->getName());
//                    }
                    continue;
                }

                $classes[$meta->getName()] = $groups;
            }

        $this->io = new SymfonyStyle($input, $output);

        foreach ($classes as $class=>$groups) {
            $indexName = $this->meiliService->getPrefixedIndexName((new \ReflectionClass($class))->getShortName());

            $this->io->title($indexName);
            if ($reset=$input->getOption('reset')) {
                $this->meiliService->reset($indexName);
            }

            // skip if no documents?  Obviously, docs could be added later, e.g. an Owner record after import
//            $task = $this->waitForTask($this->getMeiliClient()->createIndex($indexName, ['primaryKey' => Instance::DB_CODE_FIELD]));

            // pk of meili  index might be different than doctine pk, e.g. $imdbId
            $batchSize = $input->getOption('batch-size');

        }

        $this->io->success($this->getName() . ' complete.');
        return self::SUCCESS;

    }

}
