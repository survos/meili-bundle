<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Liquid\Template;
use Meilisearch\Client;
use Meilisearch\Contracts\CancelTasksQuery;
use Meilisearch\Contracts\TasksQuery;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yethee\Tiktoken\EncoderProvider;

#[AsCommand('meili:settings:update', 'Update Meilisearch index settings from compiler-pass schema')]
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

        #[Argument('Filter by index name, without prefix')]
        ?string $indexName = null,

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

        #[Option('Update the embedders', name: 'embed')]
        bool $updateEmbedders = false,

        #[Option('Filter by FQCN or short class name')]
        ?string $class = null,
    ): int {
        $wait ??= true;

        $baseTargets = $this->resolveTargets($indexName, $class);

        $targets = [];
        foreach ($baseTargets as $baseUid) {
            $settings = $this->meili->getIndexSetting($baseUid);

            if ($this->meili->isMultiLingual) {
                foreach ($this->localeContext->getEnabled() as $locale) {
                    $targets[] = [
                        'uid' => $this->meili->localizedUid($baseUid, $locale),
                        'base' => $baseUid,
                        'locale' => $locale,
                        'settings' => $settings,
                    ];
                }
            } else {
                $targets[] = [
                    'uid' => $baseUid,
                    'base' => $baseUid,
                    'locale' => null,
                    'settings' => $settings,
                ];
            }
        }

        if ($targets === []) {
            $io->warning('No matching indexes. Use --index or --class to filter. or --all?');
            return Command::SUCCESS;
        }

        foreach ($targets as $t) {
            $uid        = $t['uid'];
            $settings   = $t['settings'];
            $locale     = $t['locale'];

            $io->section("Processing {$uid} (locale={$locale})");

            if ($reset) {
                $this->meili->reset($uid);
            }

            if ($force) {
                $index = $this->meili->getOrCreateIndex(
                    $uid,
                    $settings['primaryKey'],
                    wait: $wait
                );
                $task = $index->updateSettings($settings['schema']);
                if ($wait) {
                    $task = $task->wait();
                }
            } else {
                $io->warning("Use --force to apply settings to {$uid}");
            }
        }

        if ($dumpSettings) {
            foreach ($targets as $uId) {
                // internal, from compiler pass, no network call
                $settings = $this->meili->getIndexSetting($uId);
                $io->section(sprintf('Index "%s"', $uId));
                if (!$index = $this->meili->getIndex($uId, autoCreate: false)) {
                    $io->warning('Index "'.$uId.'" has not yet been created.');
                } else {
                    $actualSettings = $index->getSettings();
                    $io->writeln(json_encode($actualSettings, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                    // @todo: compare!
//                dump($actualSettings, $settings['schema']);
                    // is update different than create?
//                $task = $index->updateSettings($settings['schema']);
//                dump($task);
                }
//                if ($io->isVerbose()) {
//                    $io->writeln("Target settings:\n\n" . json_encode($settings['schema'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
//                }
            }
        }

// constructor-inject ResolvedEmbeddersProvider $embedders

        if (false)
        foreach ($targets as $uId) {

            $io->section(sprintf('Processing index "%s"', $uId));
//            $uid = $this->prefixed($name);

            $settings = $this->meili->getIndexSetting($uId);

            $embedders = $this->embeddersProvider->forMeili();
            $pending = $this->pendingTasks($uId);
            if (!$reset && $pending > 0) {
                $io->error(sprintf('Index "%s" has %d pending tasks. Re-run with --reset.', $index->getUid(), $pending));
                return Command::FAILURE;
            }

            if ($reset) {
                $io->warning('Reset: canceling tasks and deleting index… ' . $uId);
                $resp = $this->meili->getTasks($uId,  MeiliTaskStatus::ACTIVE);
                $tasks = $this->meili->getMeiliClient()->getTasks(
                    new TasksQuery()->setIndexUids([$uId])
                        ->setStatuses(MeiliTaskStatus::ACTIVE)
                );
                foreach ($resp->getIterator() as $task) {
                    $io->warning(sprintf("Cancelling %s %s", $task['taskUid'], $task['type']));
                }
                $cancelledTasks = $this->meili->getMeiliClient()->cancelTasks(new CancelTasksQuery()
                    ->setIndexUids([$uId])
                    ->setStatuses(MeiliTaskStatus::ACTIVE)
                );
                // reset does not require --force
                $this->meili->reset($uId);
//                foreach ($resp['results'] ?? [] as $task) {
//                    dd($task);
//                }

//                $this->cancelTasks($index->getUid(), $io);
                // reset is debatable here
//                $this->deleteIndexIfExists($uId, $io);
//                $index = $this->meili->getIndex($prefixedName, $settings['primaryKey']??'id');
//                dd($index, $index->getUid(), $index->getPrimaryKey());

                // debatable!
//                $task = $this->meili->getMeiliClient()->createIndex($uId, ['primaryKey' => $settings['primaryKey']]);
//                $this->meili->waitForTask($task);

//                $index = $this->meili->getOrCreateIndex($uId, $settings['primaryKey']??'id');
            }

//            if (!$force) {
//                $io->note('Dry run (no --force): settings NOT applied.');
//                continue;
//            }

            if ($force) {
                $index = $this->meili->getOrCreateIndex($uId, $settings['primaryKey']??'id', wait: $wait);
                $task = $index->updateSettings($settings['schema']);
                $io->writeln(sprintf('updateSettings taskUid=%s', $task->getTaskUid()));
                if ($wait) {
                    $io->writeln(sprintf('waiting for task to update settings...'));
                    $task = $task->wait();
                    $io->writeln(sprintf("%s %s %s", $uId, $task->getType()->value, $task->getStatus()->value));
                }
            } else {
                $io->warning(sprintf('use --force to apply settings to "%s"', $uId));
            }

            $totalTokens = [];
            $embedderKeys = $settings['embedders'] ?? [];
            if ($embedders !== []) {
                // resolve api keys from params if provided as parameter names
                foreach ($embedders as $uId => &$cfg) {
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
                    $templates = [];
                    foreach ($embedderKeys as $key) {
                        $embeddersForThisIndex[$key] = $embedder = $embedders[$key];
                        $templateFilename = $embedder['documentTemplate'];
                        $embedder['documentTemplate'] = file_get_contents($templateFilename);
                        $embeddersForThisIndex[$key]['documentTemplate'] = file_get_contents($templateFilename);

//                        $content = file_get_contents($templateFilename);

//                        $templates[$key]->parse($content);
//                        dd($templates[$key]);
                    }
                    if ($cost) {
                        if (!class_exists(Template::class)) {
                            throw new \RuntimeException("composer req liquid/liquid");
                        }
                        foreach ($embeddersForThisIndex as $embedderName => $embedder) {
                            $templates[$embedderName] = new Template($templateFilename);
                            $totalTokens[$embedderName] = 0;
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
                                SurvosUtils::assertKeyExists($embedderName, $templates);
                                $template = $templates[$embedderName];
                                $text = $template->render(['doc' => $data]);
                                dd($text);

                                $provider = new EncoderProvider();
                                $encoder = $provider->getForModel($embedder['model']); // or 'gpt-3.5-turbo'
                                $tokens = $encoder->encode($text);
                                $tokenCount = count($tokens);
                                $totalTokens[$embedderName] += $tokenCount;
                                dd($embedder['model'], $embedderName, $tokenCount, $text);
                            }
                        }
                        dump($totalTokens);
                        foreach ($embeddersForThisIndex as $embedderName => $embedder) {
                            $io->writeln("$embedderName tokens: " . $totalTokens[$embedderName]);
                        }
                    }

                    if ($force && $updateEmbedders) {
                        $embeddersTask = $index->updateEmbedders($embeddersForThisIndex);
                        if ($wait) {
                            $task = $task->wait();
                            $io->writeln(sprintf('Update embedders: %s', $embeddersTask->getStatus()->value));
                        }
                    }


                }

            }

            if ($io->isVerbose()) {
                $io->info(json_encode($settings['schema'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            }

        }

        return Command::SUCCESS;
    }
}
