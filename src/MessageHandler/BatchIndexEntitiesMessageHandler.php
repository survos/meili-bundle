<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\MeiliBundle\Message\BatchIndexEntitiesMessage;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
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
        $groups          = $this->settingsService->getNormalizationGroups($message->entityClass);

        $index = $this->getMeiliIndex($message->indexName, $message->entityClass, $message->locale);

        if ($message->reload) {
            // Load → normalize → upload as NDJSON (chunked)
            $iter = $this->yieldNormalizedDocs(
                $repo,
                $identifierField,
                $message->entityData,
                $groups
            );
            $this->uploader->uploadDocuments($index, $iter);
        } else {
            // Already-normalized docs: send directly
            $this->uploader->uploadDocuments($index, $message->entityData);
        }
    }

    private function getMeiliIndex(?string $indexName, string $entityClass, ?string $locale): Indexes
    {
        if ($indexName) {
            return $this->meiliService->getOrCreateIndex($indexName, autoCreate: true);
        }
        $short = (new \ReflectionClass($entityClass))->getShortName();
        $loc   = $locale ?: $this->localeContext?->getDefault();
        $name  = $this->meiliService->getPrefixedIndexName(sprintf('%s%s', $short, $loc ? "_$loc" : ''));
        return $this->meiliService->getOrCreateIndex($name, autoCreate: true);
    }

    /**
     * Generator to yield normalized docs one-by-one (keeps memory flat).
     * @param iterable<int|string> $ids
     */
    private function yieldNormalizedDocs(object $repo, string $idField, iterable $ids, array $groups): \Generator
    {
        foreach ($ids as $id) {
            $entity = $repo->find($id);
            if (!$entity) { continue; }
            $doc = $this->normalizer->normalize($entity, format: null, context: ['groups' => $groups]);
            if (!\is_array($doc)) { continue; }
            yield $doc;
        }
    }
}
