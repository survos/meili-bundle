<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Service\IndexNameResolver;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Util\ResolvedEmbeddersProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\Attribute\Required;

abstract class MeiliBaseCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    #[Required]
    public MeiliService $meili;

    #[Required]
    public IndexNameResolver $indexNameResolver;

    #[Required]
    public ?LocaleContext $localeContext = null;

    #[Required]
    public ?ResolvedEmbeddersProvider $embeddersProvider = null;

    #[Required]
    public ?EntityManagerInterface $entityManager = null;

    #[Required]
    public ?NormalizerInterface $normalizer = null;

    #[Required]
    public ?MeiliPayloadBuilder $payloadBuilder = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        protected readonly ?string $projectDir = null,
    ) {
        parent::__construct();
    }

    /**
     * Call at the start of every __invoke() to enforce invariants.
     */
    protected function init(): void
    {
        // IMPORTANT: your MeiliService currently exposes isMultiLingual as a property hook,
        // but some projects may not have BabelBundle installed.
        if ($this->meili->isMultiLingual && $this->localeContext === null) {
            throw new \LogicException(
                'LocaleContext is required when MultiLingual mode is enabled. Install survos/babel-bundle.'
            );
        }
    }

    /**
     * @return list<string> base names (UNPREFIXED) from compiler pass settings (raw registry)
     */
    protected function resolveTargets(?string $index, ?string $class): array
    {
        $names = [];

        if ($class) {
            if (!class_exists($class)) {
                $class = 'App\\Entity\\' . $class;
            }
            if (!class_exists($class)) {
                throw new \InvalidArgumentException(sprintf('Class "%s" does not exist', $class));
            }
        }

        foreach ($this->meili->getRawIndexSettings() as $rawName => $setting) {
            // $rawName is the unprefixed name key in rawSettings
            if ($index && $rawName !== $index) {
                continue;
            }
            if ($class && $class !== ($setting['class'] ?? null)) {
                continue;
            }

            // Prefer explicit baseName when present, else the raw name
            $names[] = (string)($setting['baseName'] ?? $rawName);
        }

        return $names;
    }

    public function pendingTasks(string $uid): int
    {
        $resp = $this->meili->getTasks($uid, MeiliTaskStatus::ACTIVE);
        return \count($resp['results'] ?? []);
    }

    public function cancelTasks(string $uid, SymfonyStyle $io): void
    {
        // TODO: implement if you still want this.
        $io->warning('cancelTasks not implemented (yet).');
    }
}
