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
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
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
        private readonly MeiliPayloadBuilder $meiliPayloadBuilder,
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
            dd(self::$dispatching, $this->messageBus);
            return;
        }

        self::$dispatching = true;
            $this->dispatchPendingMessages();
        try {
        } finally {
            self::$dispatching = false;
        }
    }

    private function getPrimaryKey(string $class): ?string
    {
        $indexes = $this->meiliService->indexedByClass()[$class];
        foreach ($indexes as $index) {
            return $index['primaryKey'];
        }
//        if ($index = $this->meiliService->indexedByClass()[$class][0]??null) {
//            return $index['primaryKey'];
//        }
        assert(false, "Missing pk/index in $class");
        return null;

    }

    private function dispatchPendingMessages(): void
    {
        // Batch index operations by entity class
        foreach ($this->pendingIndexOperations as $entityClass => $objects) {
            // This keeps pixie and babel from accidentally getting added.
            if (!$this->meiliService->shouldAutoIndex($entityClass)) {
                continue;
            }
            // @AI: The problem is here!  pixieBundle\\Row is in this list, and shouldn't be.
//            dd($entityClass, $this->meiliService->indexedEntities);

            $groups = $this->settingsService->getNormalizationGroups($entityClass);
            $normalized = [];
            foreach ($objects as $object) {
//                $normalized[] = $this->meiliPayloadBuilder->build($object, $groups);
//                dd($normalized[0]);
            }


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
            if ($transport = $this->meiliService->getConfig()['transport']) {
                $stamps[] = new TransportNamesStamp($transport);
            }

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
                $message = new BatchIndexEntitiesMessage(
                    $entityClass,
                    $normalized,
                    reload: false
                );
                    $this->messageBus->dispatch($message, $stamps);
                try {
                } catch (\Exception $e) {
                    dd($entityClass, $normalized[0], $e);

                }
            }
        }

        // Batch remove operations by entity class
        foreach ($this->pendingRemoveOperations as $entityClass => $operations) {
            $primaryKey = $this->getPrimaryKey($entityClass);
            $entityIds = array_column($operations, $primaryKey);

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
//        return;



        if (!in_array($object::class, $this->meiliService->indexedEntities)) {
            return;
        }

        // normalization may be slow, so move this to the message handler
        $id = $this->propertyAccessor->getValue($object, $this->getPrimaryKey($object::class));

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
