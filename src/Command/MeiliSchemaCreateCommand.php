<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Meilisearch\Client;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Meili\MeiliTaskType;
use Survos\MeiliBundle\Meili\Task;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('meili:schema:create', '(re)-create Meilisearch index.  Does NOT update settings')]
final class MeiliSchemaCreateCommand extends MeiliBaseCommand
{
    public function __invoke(
        SymfonyStyle $io,

        #[Option('Dump settings without applying', name: 'dump')]
        bool $dumpSettings = false,

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
        $client = $this->meili->getMeiliClient();
        $wait ??= true;

        $targets = $this->resolveTargets($index, $class);
        if ($targets === []) {
            $io->warning('No matching indexes. Use --index or --class to filter. or --all?');
            return Command::SUCCESS;
        }

            foreach ($targets as $name) {
                $io->section(sprintf('Index "%s"', $name));
                $tr = $this->meili->getTasks($name, types: [MeiliTaskType::INDEX_CREATION], statuses: MeiliTaskStatus::ACTIVE);
                if ($tr->count()) {
                    // really it's okay to have pending tasks but probably not desirable.
                    $io->error(sprintf('Index "%s" has %d tasks.', $name, $tr->count()));
                    continue;
                }

                // check if the index already exists.  BUT we could also already have an index delete in the queue!
                $settings = $this->meili->getRawIndexSetting($name);

                $task = new Task($client->createIndex($settings['prefixedName'], ['primaryKey' => $settings['primaryKey']]));
                if ($wait) {
                    $this->meili->waitForTask($task, stopOnError: false);
                } else {
                    $io->writeln(sprintf("%s dispatched", $task));
                    // log
                }
            }

        return Command::SUCCESS;
    }


}
