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
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Message\BatchRemoveEntitiesMessage;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\TraceableMessageBus;
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

    private static bool $dispatching = false;

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
        if (self::$dispatching || !$this->messageBus) {
            return;
        }

        self::$dispatching = true;
        try {
            $this->dispatchPendingMessages();
        } finally {
            self::$dispatching = false;
        }
    }

    private function dispatchPendingMessages(): void
    {
        // Batch index operations by entity class
        foreach ($this->pendingIndexOperations as $entityClass => $objects) {
            // this isn't necessary, because they won't be added if not indexed. Debugging when messages were in doctrine too
//            if (!in_array($entityClass, $this->meiliService->indexedEntities)) {
//                $this->logger?->warning(sprintf("Skipping entity class %s (not indexed)", $entityClass));
//                continue;
//            }
            $groups = $this->settingsService->getNormalizationGroups($entityClass);
            $normalized = $this->normalizer->normalize($objects, 'array', ['groups' => $groups]);
            SurvosUtils::removeNullsAndEmptyArrays($normalized);

            $this->logger?->info(sprintf(
                "Dispatching batch index message for %d %s entities",
                count($objects),
                $entityClass
            ));

            $stamps = [];
//            $stamps[] = new TransportNamesStamp('meili');
            if (class_exists(TagStamp::class)) {
                $stamps[] = new TagStamp(new \ReflectionClass($entityClass)->getShortName());
            }
            $stamps = [
                //new TransportNamesStamp('meili')
                //use jwage/amqp-transport
                new AmqpStamp('meili'),
            ];

            if ($fancyNewWay = false) {
                $plan  = $this->meiliService->makePlan(
                    entityClass:    $entityClass,
                    locale:         $languuageForIndex,   // e.g. 'en'
                    explicitIndexName: $indexName,       // or null to derive
                    primaryKeyName: $pk,
                    transport:      $transport
                );

// optional: force index creation + set language now
                $this->meiliService->getOrCreateLocaleIndex(
                    entityClass:     $plan->entityClass,
                    locale:          $plan->locale,
                    explicitIndexName: $plan->indexName,
                    primaryKeyName:  $plan->primaryKeyName,
                    autoCreate:      true
                );

// later, per batch of ids:
                $this->meiliService->dispatchBatchForPlan($plan, $chunk, reload: true);

            } else {
                try {
                    $this->messageBus->dispatch(new BatchIndexEntitiesMessage(
                        $entityClass,
                        $normalized,
                        reload: false
                    ), $stamps);
                } catch (\Exception $e) {
                    dd($entityClass, $normalized, $e);

                }
            }
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
        $this->scheduleForIndexing($args->getObject());
    }

    private function scheduleForIndexing(object $object): void
    {
        // @todo: we need a way to disable this from the bundle config!!
        return;



        if (!in_array($object::class, $this->meiliService->indexedEntities)) {
            return;
        }

        // normalization may be slow, so move this to the message handler
        $id = $this->propertyAccessor->getValue($object, 'id');

        // BUT the entity is already hydrated, so maybe it _is_ better to do it here.

        if (!$id) {
            $this->logger?->warning(sprintf(
                "Cannot schedule entity %s for indexing: no ID found",
                $object::class
            ));
            return;
        }
        $this->pendingIndexOperations[$object::class][] = $object;
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
