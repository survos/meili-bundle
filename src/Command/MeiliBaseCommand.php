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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%kernel.project_dir%')]
        protected readonly string $projectDir,
//        protected ?LoggerInterface $logger=null,
    ) {
        parent::__construct();
    }


    /** @return string[] array of PREFIXED index names */
    protected function resolveTargets(?string $index, ?string $class): array
    {

        $names = [];
        if ($class) {
            if (!class_exists($class)) {
                $class = 'App\\Entity\\' . $class;
            }
            if (!class_exists($class)) {
                throw new \Exception('Class "' . $class . '" does not exist');
            }
        }
        foreach ($this->meili->getRawIndexSettings() as $indexName => $setting) {
            if ($index && $indexName !== $index) {
                continue;
            }
            if ($class && $class !== $setting['class']) {
                continue;
            }
            $names[] = $setting['prefixedName'];
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
    }

}
