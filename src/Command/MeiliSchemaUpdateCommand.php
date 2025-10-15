<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Liquid\Template;
use Meilisearch\Client;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Meili\Task;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yethee\Tiktoken\EncoderProvider;

#[AsCommand('meili:schema:update', 'Update Meilisearch index settings from compiler-pass schema')]
final class MeiliSchemaUpdateCommand extends MeiliBaseCommand
{
    /** @param array<string,array<string,mixed>> $indexSettings (indexName => settings)
     *  @param array<string,string>               $indexEntities (indexName => FQCN)
     */
//    public function __construct(
//        public readonly MeiliService $meili,
//    ) {
//        parent::__construct();
//    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Dump settings without applying', name: 'dump')]
        bool $dumpSettings = false,

        #[Option('calculate the cost of the embedders.  Works with --dry')]
        ?bool $cost = null,

        #[Option('Wait for task to complete')]
        bool $wait = false,

        #[Option('Apply changes (send updateSettings)')]
        bool $force = false,

        #[Option('Cancel tasks and delete index before applying')]
        bool $reset = false,

        #[Option('Filter by index name')]
        ?string $index = null,

        #[Option('Filter by FQCN or short class name')]
        ?string $class = null,
    ): int {
        if ($reset) {
            $force = true;
        }
        $wait ??= true;

        $targets = $this->resolveTargets($index, $class);
        if ($targets === []) {
            $io->warning('No matching indexes. Use --index or --class to filter. or --all?');
            return Command::SUCCESS;
        }

        if ($dumpSettings) {
            foreach ($targets as $name) {
                $io->section(sprintf('Index "%s"', $name));
                $index = $this->meili->getIndex($this->meili->getPrefixedIndexName($name));
                $settings = $this->meili->getRawIndexSetting($name);
                dd($settings, $name);
                // is update different than create?
//                $task = $index->updateSettings($settings['schema']);
//                dump($task);
                $io->writeln(json_encode($settings['schema'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));


            }
            if (!$force) {
                return Command::SUCCESS;
            }
        }

// constructor-inject ResolvedEmbeddersProvider $embedders


        foreach ($targets as $name) {
            $io->section(sprintf('Processing index "%s"', $name));
//            $uid = $this->prefixed($name);

            $settings = $this->meili->getRawIndexSetting($name);

            $embedders = $this->embeddersProvider->forMeili();
//            $task = $index->updateEmbedders($embedders);

            $index = $this->meili->getIndex($this->meili->getPrefixedIndexName($name), $settings['primaryKey']??'id');
            $pending = $this->pendingTasks($name);
            if (!$reset && $pending > 0) {
                $io->error(sprintf('Index "%s" has %d pending tasks. Re-run with --reset.', $index->getUid(), $pending));
                return Command::FAILURE;
            }

            if ($reset) {
                $io->warning('Reset: canceling tasks and deleting index…');
//                $this->cancelTasks($index->getUid(), $io);
                $this->deleteIndexIfExists($index->getUid(), $io);
            }

//            if (!$force) {
//                $io->note('Dry run (no --force): settings NOT applied.');
//                continue;
//            }

            if ($force) {
                $task = new Task($index->updateSettings($settings['schema']));
                $io->writeln(sprintf('updateSettings taskUid=%s', (string)$task->taskUid));
            }

            $totalTokens = [];
            $embedderKeys = $settings['embedders'] ?? [];
            if ($embedders !== []) {
                // resolve api keys from params if provided as parameter names
                foreach ($embedders as $name => &$cfg) {
                    if (!empty($cfg['apiKeyParameter'])) {
                        $paramName = $cfg['apiKeyParameter'];
//                        $cfg['apiKey'] = $this->params->get($paramName) ?? getenv($paramName) ?? null;
                        $cfg['apiKey'] = 'API_KEY'; // getenv($paramName) ?? null;

                        if (!$cfg['apiKey']) {
                            throw new \RuntimeException("API Key parameter ($paramName) not defined");
                        }
                        unset($cfg['apiKeyParameter']);
                    }
                }
                // Wrap into the structure Meilisearch expects: [ name => [ ...config... ] ]

                if ($embedderKeys) {
                    $embeddersForThisIndex = [];
                    foreach ($embedderKeys as $key) {
                        $embeddersForThisIndex[$key] = $embedders[$key];
                    }
                    dump($embeddersForThisIndex);
                    if ($cost) {
                        foreach ($embeddersForThisIndex as $embedderName => $embedder) {
                            $totalTokens[$embedderName] = 0;
                            $templates[$embedderName] = new Template();
                            $templates[$embedderName]->parse($embedder['documentTemplate']);
                        }

                        // iterate through the records, render the liquid template, and pass to totenizer estmiate
                        // @todo: batches, etc.  Argh. Should this be a separate command?
                        $iterator = $this->entityManager->getRepository($settings['class'])->createQueryBuilder('e')->select('e')
                            ->setMaxResults(3)
                            ->getQuery()
                            ->toIterable();
                        foreach ($iterator as $e) {
                            // chicken and egg -- we want to get the data from meili, it's exact, but we don't want to add it if the embedder is active.
                            $data = $this->payloadBuilder->build($e, $settings['persisted']);
//                            $data = $this->normalizer->normalize($e, 'array');
//                            dump($data);
                            foreach ($embeddersForThisIndex as $embedderName => $embedder) {
                                $text = $templates[$embedderName]->render(['doc' => $data]);

                                $provider = new EncoderProvider();
                                $encoder = $provider->getForModel($embedder['model']); // or 'gpt-3.5-turbo'
                                $tokens = $encoder->encode($text);
                                $tokenCount = count($tokens);
                                $totalTokens[$embedderName] += $tokenCount;
                                dump($embedder['model'], $embedderName, $tokenCount);
                            }
                        }
                        dump($totalTokens);
                        foreach ($embeddersForThisIndex as $embedderName => $embedder) {
                            $io->writeln("$embedderName tokens: " . $totalTokens[$embedderName]);
                        }
                    }

                    if ($force) {
                        $embeddersTask = new Task($index->updateEmbedders($embeddersForThisIndex));
                        $res  = $this->meili->waitForTask($embeddersTask);
                        if (!$embeddersTask->succeeded) {
                            dump($embeddersTask);
                            throw new \RuntimeException('Embedders update failed: '.json_encode($res));
                        }
                    }


                }

            }


            $io->writeln(json_encode($settings['schema'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

            try {
                $this->meili->waitForTask($task['taskUid'] ?? 0, $index, true, 50);
                $io->success('Settings updated.');
            } catch (\Throwable) {
                $io->warning('Settings update task still in progress.');
            }
        }

        return Command::SUCCESS;
    }
}
