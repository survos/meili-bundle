<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Spool\JsonlSpooler;

#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::postFlush)]
final class MeiliSpoolDoctrineListener
{
    /** @var array<class-string,array<string,bool>> */
    private array $pendingIds = [];

    public function __construct(
        private readonly JsonlSpooler $spooler,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $locale = null,
        private readonly bool $enabled = true,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        if (!$this->enabled) { return; }
        $this->collect($args->getObject(), $args);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        if (!$this->enabled) { return; }
        $this->collect($args->getObject(), $args);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->enabled || !$this->pendingIds) { return; }
        foreach ($this->pendingIds as $class => $map) {
            $ids = array_keys($map);
            $path = $this->spooler->appendIds($class, $ids, $this->locale);
            $this->logger?->info('Meili spooled IDs', ['class' => $class, 'count' => count($ids), 'file' => $path]);
        }
        $this->pendingIds = [];
    }

    private function collect(object $obj, $args): void
    {
        $em = $args->getObjectManager();
        $id = $em->getClassMetadata($obj::class)->getIdentifierValues($obj)['id'] ?? null;
        if ($id !== null) {
            $this->pendingIds[$obj::class][(string)$id] = true;
        }
    }
}
