<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Service\IndexFastSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('meili:fast-sync', 'Fetch /indexes once (/stats), upsert minimal fields, and enqueue updates as needed')]
final class FastSyncIndexesCommand
{
    public function __construct(
        private readonly IndexFastSyncService $sync,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Enqueue UpdateIndexInfoMessage for newer indexes', 'enqueue')] bool $enqueue = true,
    ): int {
        $io->title('Meilisearch → Doctrine (FAST)');

        $stats = $this->sync->fastSync($enqueue);

        $io->success(sprintf(
            'Total=%d, created=%d, updated=%d, unchanged=%d, enqueued=%d',
            $stats['total'], $stats['created'], $stats['updated'], $stats['unchanged'], $stats['enqueued']
        ));

        return Command::SUCCESS;
    }
}
