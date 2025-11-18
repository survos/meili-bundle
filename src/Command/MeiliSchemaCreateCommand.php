<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Meilisearch\Client;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Meili\MeiliTaskType;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function Symfony\Component\String\u;

#[AsCommand('meili:schema:create', '(re)-create Meilisearch index.  Does NOT update settings')]
final class MeiliSchemaCreateCommand extends MeiliBaseCommand
{
    public function __invoke(
        SymfonyStyle $io,

        #[Argument('limit to just this index name')]
        ?string $index = null,

        #[Option('Dump settings without applying', name: 'dump')]
        bool $dumpSettings = false,

        #[Option('Wait for task to complete')]
        bool $wait = false,

        #[Option('Apply changes (send updateSettings)')]
        bool $force = false,

        #[Option('Cancel tasks and delete index before applying')]
        bool $reset = false,


        #[Option('Filter by FQCN or short class name')]
        ?string $class = null,
    ): int {
        $client = $this->meili->getMeiliClient();
        $wait ??= true;

        $targets = $this->resolveTargets($index, $class);
        if ($targets === []) {
            $io->warning('No matching indexes. pass an index or use --class to filter. or --all?');
            return Command::SUCCESS;
        }

            foreach ($targets as $uId) {
                $io->section(sprintf('Index "%s"', $uId));
                $tr = $this->meili->getTasks($uId, statuses: MeiliTaskStatus::ACTIVE, types: [MeiliTaskType::INDEX_CREATION]);

                if ($tr->count()) {
                    // really it's okay to have pending tasks but probably not desirable.
                    $io->error(sprintf('Index "%s" has %d tasks.', $uId, $tr->count()));
                    if ($reset) {
                        // cancel all tasks and delete the index.
                        foreach ($tr as $task) {

                        }
                        $force = true;
                    }
                }

                if ($reset) {
                    $task = $this->meili->getMeiliClient()->deleteIndex($uId);
                    if ($wait) {
                        $deleteTask = $task->wait();
                        dump(deleteTaskAfterWait: $deleteTask->getStatus(), msg: $deleteTask->getError());
                    }
//                    dd(originalTask: $task->getStatus(), uId: $name);
                }

                // these come from the compiler pass
                $settings = $this->meili->getIndexSetting($uId);
                // this ONLY creates the index with a pk.  Settings must be applied separately
                $task = $client->createIndex($uId, ['primaryKey' => $settings['primaryKey']]);
//                $survosTask = new Task();
                    dump($task->getTaskUid(), $task->getDetails());
                    if ($wait) {
                        $x = $task->wait();
                        // this fetches the index info, real-time, returns the endpoint
                        $i = $client->getIndex($uId);
                        // just returns the endpoint
                        $ii = $client->index($uId);
//                        dd($i->getPrimaryKey(), $x->getDuration(), $x->getStatus(), $x::class, $x->getError(), $x->getType());
                    }

                    $io->writeln(sprintf("%s %s dispatched", $task->getType(), $uId));
            }

        return Command::SUCCESS;
    }


}
