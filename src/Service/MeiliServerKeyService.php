<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Exceptions\ApiException;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Repository\IndexInfoRepository;

use function is_string;
use function sha1;
use function sprintf;
use function substr;

final class MeiliServerKeyService
{
    public const SEARCH_KEY_ALIAS = 'readonly_search';

    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly ?IndexInfoRepository $indexInfoRepository = null,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * @param list<string> $indexUids
     * @return array<string,array{apiKey:string,keyUid:string,created:bool}>
     */
    public function ensureServerKeys(array $indexUids): array
    {
        $searchKey = $this->ensureKey(self::SEARCH_KEY_ALIAS, ['search', 'documents.get', 'indexes.get']);
        $keys = [self::SEARCH_KEY_ALIAS => $searchKey];

        foreach ($indexUids as $indexUid) {
            $entity = $this->loadOrCreateIndexInfo($indexUid);
            $entity->setServerKeyAccess(self::SEARCH_KEY_ALIAS, $searchKey['apiKey'], $searchKey['keyUid']);
        }

        $this->entityManager?->flush();

        return $keys;
    }

    public function resolveApiKey(string $indexUid): ?string
    {
        $entity = $this->indexInfoRepository?->find($indexUid);

        return $entity?->getServerApiKey(self::SEARCH_KEY_ALIAS);
    }

    /**
     * Sync an already-existing managed search key into index registry.
     */
    public function syncRegistryKey(string $indexUid): bool
    {
        if ($this->entityManager === null || $this->indexInfoRepository === null) {
            return false;
        }

        $keyUid = $this->buildKeyUid(self::SEARCH_KEY_ALIAS);

        try {
            $key = $this->meiliService->getMeiliClient()->getKey($keyUid);
        } catch (ApiException) {
            return false;
        }

        $apiKey = $key->getKey();
        if (!is_string($apiKey) || $apiKey === '') {
            return false;
        }

        $entity = $this->loadOrCreateIndexInfo($indexUid);
        $entity->setServerKeyAccess(self::SEARCH_KEY_ALIAS, $apiKey, (string) ($key->getUid() ?? $keyUid));
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param list<string> $actions
     * @return array{apiKey:string,keyUid:string,created:bool}
     */
    private function ensureKey(string $alias, array $actions): array
    {
        $keyUid = $this->buildKeyUid($alias);

        try {
            $key = $this->meiliService->getMeiliClient()->getKey($keyUid);

            return [
                'apiKey' => (string) $key->getKey(),
                'keyUid' => (string) ($key->getUid() ?? $keyUid),
                'created' => false,
            ];
        } catch (ApiException) {
        }

        $key = $this->meiliService->getMeiliClient()->createKey([
            'uid' => $keyUid,
            'name' => sprintf('Managed %s key', $alias),
            'description' => sprintf('Managed Meilisearch %s key', $alias),
            'actions' => $actions,
            'indexes' => ['*'],
            'expiresAt' => new DateTimeImmutable('+5 years'),
        ]);

        return [
            'apiKey' => (string) $key->getKey(),
            'keyUid' => (string) ($key->getUid() ?? $keyUid),
            'created' => true,
        ];
    }

    private function buildKeyUid(string $alias): string
    {
        $hash = sha1($alias . '|' . ($this->meiliService->getHost() ?? 'meili'));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    private function loadOrCreateIndexInfo(string $indexUid): IndexInfo
    {
        if ($this->indexInfoRepository === null || $this->entityManager === null) {
            throw new \RuntimeException('Index registry storage is not available; cannot persist Meili server keys.');
        }

        $entity = $this->indexInfoRepository->find($indexUid);
        if ($entity !== null) {
            return $entity;
        }

        $entity = new IndexInfo($indexUid, $this->meiliService->getIndexEndpoint($indexUid)->getPrimaryKey() ?? 'id');
        $this->entityManager->persist($entity);

        return $entity;
    }
}
