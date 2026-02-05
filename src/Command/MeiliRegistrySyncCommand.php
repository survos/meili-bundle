<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('meili:registry:sync', 'Sync Meili index info into the database')]
final class MeiliRegistrySyncCommand
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Prefix to filter indexes')]
        ?string $prefix = null,

        #[Option('Limit number of indexes')]
        ?int $limit = null,
    ): int {
        $indexes = $this->meiliService->listIndexesFast();

        $count = 0;
        foreach ($indexes as $uid => $index) {
            if ($prefix && !str_starts_with($uid, $prefix)) {
                continue;
            }
            if ($limit !== null && $count >= $limit) {
                break;
            }

            $info = $this->meiliService->getIndexEndpoint($uid)->fetchRawInfo();
            $settings = $this->meiliService->getIndexEndpoint($uid)->getSettings();
            $stats = $this->meiliService->getIndexEndpoint($uid)->stats();

            $primaryKey = $info['primaryKey'] ?? $settings['primaryKey'] ?? 'id';
            $docCount = (int) ($stats['numberOfDocuments'] ?? 0);

            $entity = $this->entityManager->getRepository(IndexInfo::class)->find($uid)
                ?? new IndexInfo($uid, $primaryKey, null);

            $entity->primaryKey = $primaryKey;
            $entity->documentCount = $docCount;
            $entity->settings = is_array($settings) ? $settings : [];
            $entity->updatedAt = isset($info['updatedAt']) ? new \DateTime($info['updatedAt']) : null;
            $entity->createdAt = isset($info['createdAt']) ? new \DateTime($info['createdAt']) : null;
            $entity->lastIndexed = new \DateTime();
            $entity->status = 'succeeded';

            $this->entityManager->persist($entity);
            $count++;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Synced %d Meili index(es)', $count));
        return Command::SUCCESS;
    }
}
