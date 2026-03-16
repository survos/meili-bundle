<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Exceptions\ApiException;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Repository\IndexInfoRepository;

use function array_merge;
use function array_unique;
use function array_values;
use function hash;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function substr;

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

    /**
     * @return array{status:string,keyUid:?string,hasApiKey:bool,source:string}
     */
    public function debugInfo(string $indexUid, string $workspace): array
    {
        $entity = $this->indexInfoRepository?->find($indexUid);
        $keyUid = $entity?->getChatWorkspaceKeyUid($workspace);
        $apiKey = $entity?->getChatWorkspaceApiKey($workspace);

        if ($apiKey !== null) {
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
        if ($this->indexInfoRepository === null || $this->entityManager === null) {
            throw new \RuntimeException('Index registry storage is not available; cannot persist chat API keys.');
        }

        $entity = $this->indexInfoRepository->find($indexUid);
        if ($entity === null) {
            $entity = new IndexInfo($indexUid, $this->fetchPrimaryKey($indexUid));
            $this->entityManager->persist($entity);
        }

        $storedKeyUid = $entity->getChatWorkspaceKeyUid($workspace);
        $storedApiKey = $entity->getChatWorkspaceApiKey($workspace);

        if ($storedKeyUid !== null && $storedApiKey !== null) {
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
            $key = $this->meiliService->getMeiliClient()->createKey([
                'uid' => $keyUid,
                'name' => sprintf('Chat access for %s (%s)', $workspace, $indexUid),
                'description' => sprintf('Scoped chat key for workspace %s restricted to index %s', $workspace, $indexUid),
                'actions' => ['*'],
                'indexes' => [$indexUid],
                'expiresAt' => new \DateTimeImmutable('+5 years'),
            ]);
        } catch (ApiException $exception) {
            try {
                $existing = $this->meiliService->getMeiliClient()->getKey($keyUid);
            } catch (ApiException) {
                throw $exception;
            }

            $existingIndexes = $existing->getIndexes() ?? [];
            if (!is_array($existingIndexes) || !in_array($indexUid, $existingIndexes, true)) {
                throw $exception;
            }

            $apiKey = $existing->getKey();
            if (!is_string($apiKey) || $apiKey === '') {
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
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \RuntimeException(sprintf('Meilisearch did not return a key value for workspace %s and index %s.', $workspace, $indexUid));
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

    private function buildKeyUid(string $indexUid, string $workspace): string
    {
        $hash = hash('sha1', $workspace . '|' . $indexUid);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    private function isValidKeyUid(?string $keyUid): bool
    {
        if (!is_string($keyUid) || $keyUid === '') {
            return false;
        }

        return preg_match('/^(urn:uuid:)?[0-9a-fA-F-]+$/', $keyUid) === 1;
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
