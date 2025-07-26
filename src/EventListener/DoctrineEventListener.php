<?php
// Listens for events and sync with workflows and meili

declare(strict_types=1);

namespace Survos\MeiliBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

//https://symfony.com/doc/current/doctrine/events.html#doctrine-lifecycle-listeners
#[AsDoctrineListener(Events::postUpdate /*, 500, 'default'*/)]
#[AsDoctrineListener(Events::preRemove /*, 500, 'default'*/)]
#[AsDoctrineListener(Events::prePersist /*, 500, 'default'*/)]
#[AsDoctrineListener(Events::postFlush /*, 500, 'default'*/)]
#[AsDoctrineListener(Events::postPersist /*, 500, 'default'*/)]
class DoctrineEventListener
{
    public function __construct(
        private MeiliService                 $meiliService,
        private SettingsService $settingsService,
        private PropertyAccessorInterface $propertyAccessor,
        private readonly NormalizerInterface $normalizer,
        private readonly ?LoggerInterface    $logger = null,
        private array                        $objectsByClass = [],
    )
    {
    }

    // the listener methods receive an argument which gives you access to
    // both the entity object of the event and the entity manager itself
    public function prePersist(PrePersistEventArgs $args)
    {
    }

    public function postFlush(PostFlushEventArgs $args)
    {
        foreach ($this->objectsByClass as $class => $objects) {
            $meiliIndex = $this->getMeiliIndex($class);
            $this->logger->info(__METHOD__ . sprintf(" adding %d %s objects to meili", count($objects), $class));
            $task = $meiliIndex->addDocuments($objects);
        }
    }

    public function postPersist(PostPersistEventArgs $args)
    {
        $this->addToMeiliIndex($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args)
    {
        $this->logger->info(__METHOD__);
        $this->addToMeiliIndex($args->getObject());
    }

    private function addToMeiliIndex(object $object)
    {
//        $errors = $this->validator->validate($object);
//        if (count($errors) > 0) {
//            dd($errors);
//        }
        static $groups;
        // we need to look for an index attribute!
//        #[Metadata('meili', true)]

        if (!in_array($object::class, $this->meiliService->indexedEntities)) {
            return;
        }
        // we need a better way to flag MeiliClasses, probably an attribute with the normalizer fields
        if (empty($groups)) {
            $groups = $this->settingsService->getNormalizationGroups($object::class);
        }

        //        // we used to use this:
//        //   $headline = (new ArticleDataTransformer())->transform($object, Headline::class, []);
//        // get the groups during the compiler pass
//        $prefix = strtolower((new \ReflectionClass($object))->getShortName());
//        $groups = ['rp','read','search','object.rp', 'marking', 'search', 'rp', 'article.story', "browse",  "$prefix.read", "$prefix.search"];
        // @todo: pk in Meili
        $id = $this->propertyAccessor->getValue($object, 'id');
        assert($id);


        $data = $this->normalizer->normalize($object, 'array', ['groups' => $groups]);
        $this->objectsByClass[$object::class][] = $data;
//        dd($data, $object->getId());
//        $this->meiliService->waitUntilFinished($meiliIndex);
//        $this->meiliService->waitForTask($task, stopOnError: true);

        // although we could do this, it's only one document, and meili has it's own queuing system.  But we could dispatch after the request is closed.
//        $this->bus->dispatch(new IndexHeadlineMessage($object->getId()));

    }

    private function getMeiliIndex(string $class): Indexes
    {
        $indexName = $this->meiliService->getPrefixedIndexName((new \ReflectionClass($class))->getShortName());
        return $this->meiliService->getIndex($indexName);

    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        // won't work with domain!  We probably need a unique meilil key in the object, then we'll have an interface, etc.
        $object = $args->getObject();
        if (!in_array($object::class, array_keys($this->appService->getSystemClasses()))) {
            return;
        }
        $task = $this->getMeiliIndex($args->getObject()::class)->deleteDocument($object->getId());
//        $this->meiliService->waitForTask($task);
    }

}
