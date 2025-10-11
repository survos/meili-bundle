<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliPayloadBuilder;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class BatchIndexEntitiesMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsService        $settingsService,
        private readonly NormalizerInterface    $normalizer,
        private readonly MeiliService           $meiliService,
        private readonly MeiliNdjsonUploader    $uploader,
        private readonly ?LocaleContext         $localeContext=null,
        private readonly ?LoggerInterface       $logger = null,
        private MeiliPayloadBuilder $payloadBuilder,
    ) {}

    #[AsMessageHandler]
    public function batchIndexEntities(BatchIndexEntitiesMessage $message): void
    {
        $locale = $message->locale ?: $this->localeContext?->getDefault();

        $runner = function () use ($message) {
            $this->apply($message);
        };

        $this->localeContext
            ? $this->localeContext->run($locale, $runner)
            : $runner();
    }

    private function apply(BatchIndexEntitiesMessage $message): void
    {
        $metadata        = $this->entityManager->getClassMetadata($message->entityClass);
        $repo            = $this->entityManager->getRepository($message->entityClass);
        $identifierField = $metadata->getSingleIdentifierFieldName();
//        $groups          = $this->settingsService->getNormalizationGroups($message->entityClass);

        $index = $this->getMeiliIndex($message->indexName, $message->entityClass, $message->locale);
        $indexSettings = $this->meiliService->settings[$message->indexName];
//        $groups = $indexSettings['groups']??[]; // this is ONLY going to work with doctrine indexes, not pixie
//        dump($message->entityClass, $groups);
        $persisted = $indexSettings['persisted'];
        $primaryKey = $indexSettings['primaryKey'];


        if ($message->reload) {
            // Load → normalize → upload as NDJSON (chunked)
            $iter = $this->yieldNormalizedDocs(
                $repo,
                $identifierField,
                $message->entityData,
                $persisted,
            );

            $this->uploader->uploadDocuments($index, $iter, $primaryKey);
        } else {
            // Already-normalized docs: send directly
            $this->uploader->uploadDocuments($index, $message->entityData, $primaryKey);
        }
    }

    private function getMeiliIndex(?string $indexName, string $entityClass, ?string $locale): Indexes
    {
        if ($indexName) {
            return $this->meiliService->getOrCreateIndex($indexName, autoCreate: true);
        }
        $short = (new \ReflectionClass($entityClass))->getShortName();
        $loc   = $locale ?: $this->localeContext?->getDefault();

        return $this->meiliService->getOrCreateIndex($indexName, autoCreate: true);
    }

    /**
     * Generator to yield normalized docs one-by-one (keeps memory flat).
     * @param iterable<int|string> $ids
     */
    private function yieldNormalizedDocs(object $repo, string $idField, iterable $ids, array $persisted): \Generator
    {
        foreach ($ids as $id) {
            $entity = $repo->find($id);
            if (!$entity) { continue; }
            $doc = $this->payloadBuilder->build($entity, $persisted);
            if (!\is_array($doc)) { continue; }
            yield $doc;
        }
    }
}
