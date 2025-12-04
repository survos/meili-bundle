<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
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
        protected ?LocaleContext $localeContext=null, // require if multiLingual: true
        protected ?ResolvedEmbeddersProvider $embeddersProvider=null,
        protected ?EntityManagerInterface $entityManager=null,
        protected ?NormalizerInterface $normalizer=null,
        protected ?MeiliPayloadBuilder $payloadBuilder=null,
        #[Autowire('%kernel.project_dir%')]
        protected readonly ?string $projectDir=null,
//        protected ?LoggerInterface $logger=null,
    ) {
        if ($this->meili->isMultiLingual && $this->localeContext === null) {
            throw new \LogicException('LocaleContext is required when MultiLingual mode is enabled. Install survos/babel-bundle.');
        }

        parent::__construct();
    }


    /** @return string[] array of PREFIXED index names */
    protected function resolveTargets(?string $index, ?string $class, ?array $locales=null): array
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
