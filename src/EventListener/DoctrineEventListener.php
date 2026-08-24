<?php

// Optimized event listener that batches operations by entity class

namespace Survos\MeiliBundle\EventListener;

use Survos\DataContracts\Util\Arrays;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
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
use Symfony\Contracts\Service\ResetInterface;

#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::preRemove)]
#[AsDoctrineListener(Events::prePersist)]
#[AsDoctrineListener(Events::postFlush)]
#[AsDoctrineListener(Events::postPersist)]
class DoctrineEventListener implements ResetInterface
{
    private array $pendingIndexOperations = [];
    private array $pendingRemoveOperations = [];

    // An instance property, not `private static`: there is exactly one of this listener, so
    // the guard was never cross-instance, and a static survives services_resetter and the whole
    // FrankenPHP worker process -- a flush killed mid-dispatch (OOM, execution-timeout: no
    // `finally`) would wedge it at true and silently stop indexing for every later request.
    private bool $dispatching = false;

    private bool $enabled = true;

    public function disable(): void
    {
        $this->enabled = false;
        // Clear any operations queued before disable() was called
        $this->pendingIndexOperations  = [];
        $this->pendingRemoveOperations = [];
    }

    public function enable(): void  { $this->enabled = true; }

    /**
     * Under FrankenPHP worker mode this listener outlives the response. Anything still queued
     * when a request dies before postFlush() would otherwise be indexed against the *next*
     * request's entity manager, and a wedged $dispatching would disable indexing for good.
     */
    public function reset(): void
    {
        $this->pendingIndexOperations  = [];
        $this->pendingRemoveOperations = [];
        $this->dispatching = false;
    }

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
        if (!$this->enabled || $this->dispatching || !$this->messageBus) {
            return;
        }

        if (empty($this->pendingIndexOperations) && empty($this->pendingRemoveOperations)) {
            return;
        }

        $this->dispatching = true;
        try {
            $this->dispatchPendingMessages();
        } finally {
            $this->dispatching = false;
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



            // Get the index configuration for this entity class
            $indexesByClass = $this->meiliService->indexedByClass();
            $entityIndexes = $indexesByClass[$entityClass] ?? [];

            // Use the first index's persisted configuration if available
            $persistedConfig = [];
            if (!empty($entityIndexes)) {
                $firstIndex = reset($entityIndexes);
                $persistedConfig = $firstIndex['persisted'] ?? [];
            } else {
                // Fallback to API groups if no MeiliIndex configuration
                $groups = $this->settingsService->getNormalizationGroups($entityClass);
                $persistedConfig = [
                    'groups' => $groups,
                    'restrict_groups' => !empty($groups)
                ];
            }

            $normalized = [];
            foreach ($objects as $object) {
                $normalized[] = $this->meiliPayloadBuilder->build($object, $persistedConfig);
            }

            Arrays::sparse($normalized);

            $this->logger?->info(sprintf(
                "Dispatching batch index message for %d %s entities",
                count($objects),
                $entityClass
            ));

            $stamps = [];
            if (class_exists(TagStamp::class)) {
                $stamps[] = new TagStamp(new \ReflectionClass($entityClass)->getShortName());
            }
            if ($transport = $this->meiliService->getConfig()['transport']) {
                $stamps[] = new TransportNamesStamp($transport);
            }

            $message = new BatchIndexEntitiesMessage(
                $entityClass,
                $normalized,
                reload: false,
                primaryKeyName: $this->getPrimaryKey($entityClass)
            );
            try {
                $this->messageBus->dispatch($message, $stamps);
            } catch (\Exception $e) {
                $this->logger?->error(sprintf(
                    "Failed to dispatch index message for %s: %s",
                    $entityClass,
                    $e->getMessage()
                ));
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
        if (!$this->enabled) {
            return;
        }

        if (!in_array($object::class, $this->meiliService->indexedEntities)) {
            return;
        }

        $id = $this->propertyAccessor->getValue($object, $this->getPrimaryKey($object::class));

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
