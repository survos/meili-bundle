<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Contracts\CreateKeyQuery;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Contracts\KeyAction;
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
        $searchKey = $this->ensureKey(self::SEARCH_KEY_ALIAS, [KeyAction::Search, KeyAction::DocumentsGet, KeyAction::IndexesGet]);
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
            $apiKey = $key->getKey();

            if (!is_string($apiKey) || !$this->keyIsUsable($apiKey)) {
                return false;
            }
        } catch (ApiException) {
            return false;
        }

        $entity = $this->loadOrCreateIndexInfo($indexUid);
        $entity->setServerKeyAccess(self::SEARCH_KEY_ALIAS, $apiKey, (string) ($key->getUid() ?? $keyUid));
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param list<KeyAction> $actions
     * @return array{apiKey:string,keyUid:string,created:bool}
     */
    private function ensureKey(string $alias, array $actions): array
    {
        $keyUid = $this->buildKeyUid($alias);

        try {
            $key = $this->meiliService->getMeiliClient()->getKey($keyUid);
            $apiKey = (string) $key->getKey();

            if ($this->keyIsUsable($apiKey)) {
                return [
                    'apiKey' => $apiKey,
                    'keyUid' => (string) ($key->getUid() ?? $keyUid),
                    'created' => false,
                ];
            }

            // The uid is registered but the key material no longer authenticates --
            // typically a Meilisearch instance reset/reseed invalidated it while the
            // uid stayed put. Delete it so createKey() below can reuse the uid.
            try {
                $this->meiliService->getMeiliClient()->deleteKey($keyUid);
            } catch (ApiException) {
            }
        } catch (ApiException) {
        }

        $key = $this->meiliService->getMeiliClient()->createKey(new CreateKeyQuery(
            actions: $actions,
            indexes: ['*'],
            name: sprintf('Managed %s key', $alias),
            description: sprintf('Managed Meilisearch %s key', $alias),
            uid: $keyUid,
            expiresAt: new DateTimeImmutable('+5 years'),
        ));

        return [
            'apiKey' => (string) $key->getKey(),
            'keyUid' => (string) ($key->getUid() ?? $keyUid),
            'created' => true,
        ];
    }

    /**
     * getKey() only proves the uid is registered on the server, not that the key
     * material still authenticates -- a Meilisearch reset/reseed (or, per this
     * incident, a version bump) can invalidate it while the cached uid lookup keeps
     * succeeding. Do one cheap authenticated call with the actual key material.
     */
    private function keyIsUsable(string $apiKey): bool
    {
        if ($apiKey === '') {
            return false;
        }

        try {
            $this->meiliService->getMeiliClient(apiKey: $apiKey)->getIndexes((new IndexesQuery())->setLimit(1));

            return true;
        } catch (ApiException $e) {
            return !in_array($e->httpStatus, [401, 403], true);
        }
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
