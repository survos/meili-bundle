<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Message\UpdateIndexInfoMessage;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Survos\MeiliBundle\Service\IndexFastSyncService;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('meili:iterate', 'iterate indexes and dispatch IndexInfoEvent')]
final class IterateIndexesCommand
{
    public function __construct(
        private readonly IndexFastSyncService $sync,
        private IndexInfoRepository           $indexInfoRepository,
        private EntityManagerInterface        $entityManager,
        private MeiliService                  $meiliService,
        private MessageBusInterface           $bus, private readonly EventDispatcherInterface $eventDispatcher,

    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('dispatch with details')] ?bool $details = null,
        #[Option('filter by prefix')] ?string $prefix = null,
        #[Option('Enqueue UpdateIndexInfoMessage for newer indexes', 'enqueue')] bool $enqueue = true,
    ): int {
        // prefix='' for all
        $prefix ??= $this->meiliService->getPrefix();
        $io->title('Meilisearch → Doctrine (FAST)');

        $info = $this->meiliService->getMeiliClient()->stats();
        foreach ($info['indexes'] as $uid => $rawInfo) {
            // filter by prefix.
            if (!str_starts_with($uid, $prefix)) {
                continue;
            }
            if ($details) {
                $details = $this->meiliService->getIndex($uid)->getSettings();
                dd($uid, $details, $rawInfo);
            }
            $rawInfo['uid'] = $uid;
            $message = new UpdateIndexInfoMessage(...$rawInfo);
            $this->eventDispatcher->dispatch($message);
        }
        return Command::SUCCESS;
        if (0) {
            dd($uid, $rawInfo);

            if (!$localInfo = $this->indexInfoRepository->find($uid)) {
                $localInfo = new IndexInfo($uid);
                $this->entityManager->persist($localInfo);
            }
            // parse dataset, locale, inverse in indexNamer
//            $localInfo->locale = $rawInfo['locale'];
//
//                // create-or-load by PK (uid)
//            $indexInfo = $this->indexInfoRepository->find($uid) ?? new IndexInfo($uid);
//            if ($indexInfo->lastSyncedAt === null) {
//                $this->entityManager->persist($indexInfo);
//            }
        }
        $this->entityManager->flush();
        dd($info);


        $stats = $this->sync->fastSync($enqueue);
        // now dispatch messages that have been updated
        $count = 0;
        foreach ($this->indexInfoRepository->findBy(['needsUpdate' => true]) as $indexInfo) {
            $this->bus->dispatch(new UpdateIndexInfoMessage($indexInfo->uid));
            $count++;
        }
        $io->success("Messages dispatched " . $count);
//        $qb = $indexInfoRepository->createQueryBuilder('indexInfo');
//        $qb->where($qb->expr()->eq('indexInfo.createdAt', ':createdAt'));


//        $io->success(sprintf(
//            'Total=%d, created=%d, updated=%d, unchanged=%d, enqueued=%d',
//            $stats['total'], $stats['created'], $stats['updated'], $stats['unchanged'], $stats['enqueued']
//        ));
        return Command::SUCCESS;
    }
}
