<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Contracts\CreateKeyQuery;
use Meilisearch\Contracts\KeyAction;
use Meilisearch\Exceptions\ApiException;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Repository\IndexInfoRepository;

final class ChatWorkspaceAccessKeyService
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly ChatWorkspaceResolver $chatWorkspaceResolver,
        private readonly ?IndexInfoRepository $indexInfoRepository = null,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function resolveApiKey(string $indexUid, string $workspace): ?string
    {
        $entity = $this->indexInfoRepository?->find($indexUid);
        $apiKey = $entity?->getChatWorkspaceApiKey($workspace);

        return $apiKey;
    }

    public function hasRegistryKey(string $indexUid, string $workspace): bool
    {
        $entity = $this->indexInfoRepository?->find($indexUid);

        return null !== $entity?->getChatWorkspaceApiKey($workspace);
    }

    /**
     * @return array{status:string,keyUid:?string,hasApiKey:bool,source:string}
     */
    public function debugInfo(string $indexUid, string $workspace): array
    {
        $entity = $this->indexInfoRepository?->find($indexUid);
        $keyUid = $entity?->getChatWorkspaceKeyUid($workspace);
        $apiKey = $entity?->getChatWorkspaceApiKey($workspace);

        if (null !== $apiKey) {
            return [
                'status' => 'registry',
                'keyUid' => $keyUid,
                'hasApiKey' => true,
                'source' => 'index_registry',
            ];
        }

        return [
            'status' => 'missing',
            'keyUid' => $keyUid,
            'hasApiKey' => false,
            'source' => 'none',
        ];
    }

    /**
     * @param array<string,mixed> $workspaceCfg
     *
     * @return list<string>
     */
    public function resolveWorkspaceIndexes(string $workspace, array $workspaceCfg): array
    {
        return $this->chatWorkspaceResolver->resolveWorkspaceIndexes($workspace, $workspaceCfg);
    }

    /**
     * @return array{apiKey:string,keyUid:string,created:bool}
     */
    public function ensureApiKey(string $indexUid, string $workspace): array
    {
        if (null === $this->indexInfoRepository || null === $this->entityManager) {
            throw new \RuntimeException('Index registry storage is not available; cannot persist chat API keys.');
        }

        $entity = $this->indexInfoRepository->find($indexUid);
        if (null === $entity) {
            $entity = new IndexInfo($indexUid, $this->fetchPrimaryKey($indexUid));
            $this->entityManager->persist($entity);
        }

        $storedKeyUid = $entity->getChatWorkspaceKeyUid($workspace);
        $storedApiKey = $entity->getChatWorkspaceApiKey($workspace);

        if (null !== $storedKeyUid && null !== $storedApiKey) {
            try {
                $key = $this->meiliService->getMeiliClient()->getKey($storedKeyUid);
                $resolvedApiKey = $key->getKey() ?? $storedApiKey;
                $entity->setChatWorkspaceAccess($workspace, $resolvedApiKey, $storedKeyUid);
                $this->entityManager->flush();

                return [
                    'apiKey' => $resolvedApiKey,
                    'keyUid' => $storedKeyUid,
                    'created' => false,
                ];
            } catch (ApiException $exception) {
                $this->logger?->warning('Stored chat key no longer exists; recreating it.', [
                    'index' => $indexUid,
                    'workspace' => $workspace,
                    'keyUid' => $storedKeyUid,
                    'exception' => $exception,
                ]);
            }
        }

        $keyUid = $this->isValidKeyUid($storedKeyUid)
            ? $storedKeyUid
            : $this->buildKeyUid($indexUid, $workspace);
        try {
            $key = $this->meiliService->getMeiliClient()->createKey(new CreateKeyQuery(
                actions: [KeyAction::Any],
                indexes: [$indexUid],
                name: \sprintf('Chat access for %s (%s)', $workspace, $indexUid),
                description: \sprintf('Scoped chat key for workspace %s restricted to index %s', $workspace, $indexUid),
                uid: $keyUid,
                expiresAt: new \DateTimeImmutable('+5 years'),
            ));
        } catch (ApiException $exception) {
            try {
                $existing = $this->meiliService->getMeiliClient()->getKey($keyUid);
            } catch (ApiException) {
                throw $exception;
            }

            $existingIndexes = $existing->getIndexes() ?? [];
            if (!\is_array($existingIndexes) || !\in_array($indexUid, $existingIndexes, true)) {
                throw $exception;
            }

            $apiKey = $existing->getKey();
            if (!\is_string($apiKey) || '' === $apiKey) {
                throw $exception;
            }

            $entity->setChatWorkspaceAccess($workspace, $apiKey, $existing->getUid() ?? $keyUid);
            $this->entityManager->flush();

            return [
                'apiKey' => $apiKey,
                'keyUid' => $existing->getUid() ?? $keyUid,
                'created' => false,
            ];
        }

        $apiKey = $key->getKey();
        if (!\is_string($apiKey) || '' === $apiKey) {
            throw new \RuntimeException(\sprintf('Meilisearch did not return a key value for workspace %s and index %s.', $workspace, $indexUid));
        }

        $resolvedKeyUid = $key->getUid() ?? $keyUid;
        $entity->setChatWorkspaceAccess($workspace, $apiKey, $resolvedKeyUid);
        $this->entityManager->flush();

        return [
            'apiKey' => $apiKey,
            'keyUid' => $resolvedKeyUid,
            'created' => true,
        ];
    }

    /**
     * Sync an already-existing chat workspace key into index registry.
     */
    public function syncRegistryKey(string $indexUid, string $workspace): bool
    {
        if (null === $this->indexInfoRepository || null === $this->entityManager) {
            return false;
        }

        $entity = $this->indexInfoRepository->find($indexUid);
        if (null === $entity) {
            $entity = new IndexInfo($indexUid, $this->fetchPrimaryKey($indexUid));
            $this->entityManager->persist($entity);
        }

        $storedKeyUid = $entity->getChatWorkspaceKeyUid($workspace);
        $keyUid = $this->isValidKeyUid($storedKeyUid)
            ? $storedKeyUid
            : $this->buildKeyUid($indexUid, $workspace);

        try {
            $key = $this->meiliService->getMeiliClient()->getKey($keyUid);
        } catch (ApiException) {
            return false;
        }

        $apiKey = $key->getKey();
        if (!\is_string($apiKey) || '' === $apiKey) {
            return false;
        }

        $entity->setChatWorkspaceAccess($workspace, $apiKey, $key->getUid() ?? $keyUid);
        $this->entityManager->flush();

        return true;
    }

    private function buildKeyUid(string $indexUid, string $workspace): string
    {
        $hash = \hash('sha1', $workspace.'|'.$indexUid);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            \substr($hash, 0, 8),
            \substr($hash, 8, 4),
            \substr($hash, 12, 4),
            \substr($hash, 16, 4),
            \substr($hash, 20, 12),
        );
    }

    private function isValidKeyUid(?string $keyUid): bool
    {
        if (!\is_string($keyUid) || '' === $keyUid) {
            return false;
        }

        return 1 === \preg_match('/^(urn:uuid:)?[0-9a-fA-F-]+$/', $keyUid);
    }

    private function fetchPrimaryKey(string $indexUid): string
    {
        try {
            return $this->meiliService->getIndexEndpoint($indexUid)->getPrimaryKey() ?? 'id';
        } catch (\Throwable) {
            return 'id';
        }
    }
}
