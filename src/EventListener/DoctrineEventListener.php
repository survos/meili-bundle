<?php

// Optimized event listener that batches operations by entity class

namespace Survos\MeiliBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Message\BatchRemoveEntitiesMessage;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::preRemove)]
#[AsDoctrineListener(Events::prePersist)]
#[AsDoctrineListener(Events::postFlush)]
#[AsDoctrineListener(Events::postPersist)]
class DoctrineEventListener
{
    private array $pendingIndexOperations = [];
    private array $pendingRemoveOperations = [];

    public function __construct(
        private readonly MeiliService              $meiliService,
        private readonly SettingsService           $settingsService,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly NormalizerInterface       $normalizer,
        private readonly ?MessageBusInterface      $messageBus=null,
        private readonly ?LoggerInterface          $logger = null,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        // Nothing to do here for now
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        // Batch index operations by entity class
        foreach ($this->pendingIndexOperations as $entityClass => $entityIds) {
//            $entitiesData = array_column($operations, 'data');

            $this->logger?->info(sprintf(
                "Dispatching batch index message for %d %s entities",
                count($entityIds),
                $entityClass
            ));

            $stamps = [];
//            $stamps[] = new TransportNamesStamp('meili');
            if (class_exists(TagStamp::class)) {
                $stamps[] = new TagStamp(new \ReflectionClass($entityClass)->getShortName());
            }
            $this->messageBus->dispatch(new BatchIndexEntitiesMessage(
                $entityClass,
                $entityIds
            ), $stamps);
        }

        // Batch remove operations by entity class
        foreach ($this->pendingRemoveOperations as $entityClass => $operations) {
            $entityIds = array_column($operations, 'id');

            $this->logger?->info(sprintf(
                "Dispatching batch remove message for %d %s entities",
                count($entityIds),
                $entityClass
            ));

            $this->messageBus->dispatch(new BatchRemoveEntitiesMessage(
                $entityClass,
                $entityIds
            ));
        }

        // Clear pending operations
        $this->pendingIndexOperations = [];
        $this->pendingRemoveOperations = [];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->scheduleForIndexing($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->logger?->info(__METHOD__);
        $this->scheduleForIndexing($args->getObject());
    }

    private function scheduleForIndexing(object $object): void
    {
        if (!in_array($object::class, $this->meiliService->indexedEntities)) {
            return;
        }

        // normalization may be slow, so move this to the message handler
        $id = $this->propertyAccessor->getValue($object, 'id');

        if (!$id) {
            $this->logger?->warning(sprintf(
                "Cannot schedule entity %s for indexing: no ID found",
                $object::class
            ));
            return;
        }



        $this->pendingIndexOperations[$object::class][] = $id;
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $object = $args->getObject();

        if (!in_array($object::class, $this->meiliService->indexedEntities)) {
            return;
        }

        $id = $this->propertyAccessor->getValue($object, 'id');

        if (!$id) {
            $this->logger?->warning(sprintf(
                "Cannot schedule entity %s for removal: no ID found",
                $object::class
            ));
            return;
        }

        $this->pendingRemoveOperations[$object::class][] = [
            'id' => $id,
        ];
    }
}
