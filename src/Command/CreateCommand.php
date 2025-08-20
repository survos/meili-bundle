<?php

namespace Survos\MeiliBundle\Command;

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
use Survos\MeiliBundle\Api\Filter\MultiFieldSearchFilter;
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
    name: 'meili:create',
    description: 'create and configure an index',
)]
class CreateCommand
{
    public function __construct(
        private MeiliService                                 $meiliService,
        protected ParameterBagInterface                      $bag,
        private LoggerInterface                              $logger,
        #[Autowire('%env(OPENAI_API_KEY)%')] private ?string $apiKey = null,
    )
    {
    }

    public function __invoke(
        SymfonyStyle         $io,
        #[Argument()] string $indexName,
        #[Option] ?string    $host = null,
        #[Option] ?string    $apiKey = null,
        #[Option] ?string    $provider = null,
        #[Option] string     $embed = 'default',
        #[Option] ?int       $embedSize = null,
        #[Option] bool       $reset = false,
    )
    {
        if ($reset) {
            $this->meiliService->reset($indexName);
        }
        $index = $this->meiliService->getOrCreateIndex($indexName);
        if (!$index) {
            $io->error("Index '{$indexName}' was not created.");
            return Command::FAILURE;
        }
        $info = $index->fetchRawInfo(); // NOT a task
        $io->success("Index '{$info['uid']}' was created.");

        if ($provider === 'openAi') {
            $embedder = [
                $embed => [
                    'source' => 'userProvided', // openAi?
                    'dimensions' => 1024, //
//                    'model' => 'text-embedding-3-small',
//                    'apiKey' => $this->apiKey,
//                    'documentTemplate' => null, // $documentTemplate,
                ]
            ];
            $task = $index->updateEmbedders(
                $embedder,
            );
            $results = $this->meiliService->waitForTask($task['taskUid']);
        }


        $settings = $index->getSettings();
        $embeddings = $index->getEmbedders();
//        $io->writeln(json_encode($settings, JSON_PRETTY_PRINT));
        $io->writeln(json_encode($embeddings, JSON_PRETTY_PRINT));

        return Command::SUCCESS;


    }


}
