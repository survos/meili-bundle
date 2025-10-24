<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Meili\MeiliTaskType;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Util\ResolvedEmbeddersProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class MeiliBaseCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    public function __construct(
        public readonly MeiliService $meili,
        protected ?ResolvedEmbeddersProvider $embeddersProvider=null,
        protected ?EntityManagerInterface $entityManager=null,
        protected ?NormalizerInterface $normalizer=null,
        protected ?MeiliPayloadBuilder $payloadBuilder=null,
//        protected ?LoggerInterface $logger=null,
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
            $task = $this->meili->getIndex($uid)->delete();
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
        $resp = $this->meili->getTasks($uid,  MeiliTaskStatus::ACTIVE);

        return \count($resp['results'] ?? []);
    }

    public function cancelTasks(string $uid, SymfonyStyle $io): void
    {
        // @todo
        foreach ($this->meili->getTasks($uid,  MeiliTaskStatus::ACTIVE) as $task) {
            $this->meili->getMeiliClient()->cancelTasks($task['taskUid']);
        }
        $resp = $this->meili->cancelTasks(['indexUids' => [$uid]]);
        $io->writeln(sprintf('cancelTasks taskUid=%s', (string)($resp['taskUid'] ?? 'unknown')));
        try { $this->meili->waitForTask($resp['taskUid'] ?? 0, 2000, 50); } catch (\Throwable) {}
    }

}
