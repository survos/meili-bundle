<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Service\ChatWorkspaceAccessKeyService;
use Survos\MeiliBundle\Service\ChatWorkspaceResolver;
use Survos\MeiliBundle\Service\MeiliServerKeyService;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;
use function str_starts_with;

#[AsCommand('meili:registry:sync', 'Sync Meili index info into the database')]
final class MeiliRegistrySyncCommand
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MeiliServerKeyService $meiliServerKeyService,
        private readonly ChatWorkspaceAccessKeyService $chatWorkspaceAccessKeyService,
        private readonly ChatWorkspaceResolver $chatWorkspaceResolver,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Prefix to filter indexes (defaults to MEILI_PREFIX from config)')]
        ?string $prefix = null,

        #[Option('Limit number of indexes')]
        ?int $limit = null,
    ): int {
        // Use configured prefix if not provided
        $prefix ??= $this->meiliService->getPrefix();

        if ($prefix) {
            $io->info(sprintf('Filtering indexes by prefix: %s', $prefix));
        }

        $indexes = $this->meiliService->listIndexesFast();

        $count = 0;
        $searchKeyExists = 0;
        $searchKeyMissing = 0;
        $chatKeyExists = 0;
        $chatKeyMissing = 0;

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
            $entity->replaceSettingsPreservingRegistry(is_array($settings) ? $settings : []);
            $entity->updatedAt = isset($info['updatedAt']) ? new \DateTime($info['updatedAt']) : null;
            $entity->createdAt = isset($info['createdAt']) ? new \DateTime($info['createdAt']) : null;
            $entity->lastIndexed = new \DateTime();
            $entity->status = 'succeeded';

            $this->entityManager->persist($entity);

            if ($this->meiliServerKeyService->syncRegistryKey($uid)) {
                ++$searchKeyExists;
            } else {
                ++$searchKeyMissing;
            }

            foreach ($this->chatWorkspaceResolver->workspaceTemplatesForIndex($uid) as $workspaceTemplate) {
                $workspace = $this->chatWorkspaceResolver->actualWorkspaceName($workspaceTemplate, $uid);
                if ($this->chatWorkspaceAccessKeyService->syncRegistryKey($uid, $workspace)) {
                    ++$chatKeyExists;
                } else {
                    ++$chatKeyMissing;
                }
            }

            $count++;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Synced %d Meili index(es)', $count));
        $io->writeln(sprintf('Search key in registry: %d found, %d missing', $searchKeyExists, $searchKeyMissing));
        $io->writeln(sprintf('Index chat keys in registry: %d found, %d missing', $chatKeyExists, $chatKeyMissing));

        return Command::SUCCESS;
    }
}
