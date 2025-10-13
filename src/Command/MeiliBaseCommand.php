<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Meilisearch\Client;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Meili\MeiliTaskType;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

class MeiliBaseCommand extends Command
{
    public function __construct(
        public readonly MeiliService $meili,
        protected ?LoggerInterface $logger=null,
    ) {
        parent::__construct();
    }


    /** @return string[] */
    protected function resolveTargets(?string $index, ?string $class): array
    {

        $names = [];
        foreach ($this->meili->getRawIndexSettings() as $indexName => $setting) {
            if ($index && $indexName !== $index) {
                continue;
            }
            if ($class && $class !== $setting['class']) {
                continue;
            }
            $names[] = $indexName;
        }
        return $names;
        $names = array_keys($this->meili->getRawIndexSettings());

        if ($index) {
            $names = array_filter($names, static fn($n) => $n === $index);
            dump($names);
        }
        if ($class) {
            $names = array_values(array_filter($names, function ($n) use ($class) {
                dd($n, $class);
                return ($this->indexEntities[$n] ?? null) === $class
                    || str_ends_with($this->indexEntities[$n] ?? '', '\\' . ltrim($class, '\\'));
            }));
            dump($names);
        }
        dd($index, $class, $names);
        return $names;
    }

    protected function  deleteIndexIfExists(string $uid, SymfonyStyle $io): void
    {
        try {
            $task = $this->meili->index($uid)->delete();
            $io->writeln(sprintf('delete index taskUid=%s', (string)($task['taskUid'] ?? 'unknown')));
            try { $this->meili->waitForTask($task['taskUid'] ?? 0, 2000, 50); } catch (\Throwable) {}
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            if ($e->getCode() === 404) {
                $io->writeln('Index did not exist.');
                return;
            }
            throw $e;
        }
    }

    public function pendingTasks(string $uid): int
    {
        $resp = $this->meili->getTasks($uid,  ['enqueued', 'processing']);

        return \count($resp['results'] ?? []);
    }

    public function cancelTasks(string $uid, SymfonyStyle $io): void
    {
        $resp = $this->meili->cancelTasks(['indexUids' => [$uid]]);
        $io->writeln(sprintf('cancelTasks taskUid=%s', (string)($resp['taskUid'] ?? 'unknown')));
        try { $this->meili->waitForTask($resp['taskUid'] ?? 0, 2000, 50); } catch (\Throwable) {}
    }

}
