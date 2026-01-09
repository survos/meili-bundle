<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Model\IndexTarget;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class IndexProducer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * Dispatch BatchIndexEntitiesMessage chunks for a target.
     */
    public function dispatchTarget(
        IndexTarget $target,
        int $batchSize = 1000,
        ?int $limit = null,
        bool $sync = true,
        bool $wait = true,
        ?string $transport = null,
        string $primaryKeyName = 'id',
    ): int {
        $stamps = [];

        if ($sync) {
            $stamps[] = new TransportNamesStamp('sync');
        } elseif ($transport) {
            $stamps[] = new TransportNamesStamp($transport);
        }

        $streamer  = new DoctrinePrimaryKeyStreamer($this->entityManager, $target->class);
        $generator = $streamer->stream($batchSize);

        $sent = 0;

        foreach ($generator as $chunk) {
            $message = new BatchIndexEntitiesMessage(
                $target->class,
                entityData: $chunk,
                reload: true,
                primaryKeyName: $primaryKeyName,
                transport: $sync ? 'sync' : $transport,
                locale: $target->locale,
                indexName: $target->uid,
                sync: $sync,
                wait: $wait,
            );

            $this->bus->dispatch($message, $stamps);
            $sent += \count($chunk);

            if ($limit !== null && $sent >= $limit) {
                break;
            }
        }

        return $sent;
    }
}
