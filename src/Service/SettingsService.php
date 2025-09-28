<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\FilterInterface;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Id;
use Survos\InspectionBundle\Services\ResourceInspector;
use Survos\MeiliBundle\Api\Filter\FacetsFieldSearchFilter;
use Survos\MeiliBundle\Metadata\MeiliId;
use Symfony\Component\Serializer\Attribute\Groups;
use function Symfony\Component\String\u;
use Survos\MeiliBundle\Metadata\Facet;

class SettingsService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    )
    {
    }

    public function getFieldsWithAttribute(array $settings, string $internalAttribute): array
    {
        $fields = [];
        foreach ($settings as $fieldName => $attributes) {
            if ($attributes[$internalAttribute]??false) {
                $fields[] = $fieldName;
            }
        }
        return $fields;
    }
    public function getSettingsFromAttributes(string $class): array
    {
        assert(class_exists($class), "Missing $class");
//        $this->resourceInspector->inspect($class);

        assert(class_exists($class), $class);
        $reflectionClass = new \ReflectionClass($class);
        $settings = [];

        // this is a hack, we should get it from ApiPlatform Meta
        // class attributes first.
        foreach ($reflectionClass->getAttributes() as $attribute) {

//            if ($attribute->getName() === ApiProperty::class) {
//                dd($attribute->getName(), $attribute->newInstance());
//            }

            //
            if (!u($attribute->getName())->endsWith('ApiFilter')) {
                continue;
            }

            // $filter = $attribute->getArguments()[0];
            // if (u($filter)->endsWith('OrderFilter')) {
            //     $orderProperties = $attribute->getArguments()['properties'];
            //     return $orderProperties;
            //            dd($attribute);
            /** @var FilterInterface $filter */
            $arguments = $attribute->getArguments();

            /** @var ApiFilter $attrInstance */
            $attrInstance = $attribute->newInstance();
//            dump($attribute->newInstance(), $arguments, $attribute->getName(), $attribute);
            $filter = $attrInstance->filterClass;
            if (!$filter) {
                dd($arguments, $attribute->getName(), $attribute);
                continue;
                return [];
            }
            $properties = $attrInstance->properties;
            foreach ($properties as $property) {
//                if (!array_key_exists($property, $settings)) {
//                    $settings[$property] = [
//                        'name' => $property,
//                        'browsable' => false,
//                        'sortable' => false,
//                        'searchable' => false
//                    ];
//                }
                switch ($filter) {
//                    case RangeFilter::class:
                    case FacetsFieldSearchFilter::class:
                        $settings[$property]['browsable'] = true;
                        break;
//                    case SortFilter::class:
//                        assert(false, "why not OrderFilter?");
                    case OrderFilter::class:
                        $settings[$property]['sortable'] = true;
                        break;

                    case SearchFilter::class:
                        foreach ($properties as $prop=>$type) {
                            if ($type === 'partial') {
                                $settings[$prop]['searchable'] = true;
                            }
                        }
                        break;
//                    case MeiliMultiFieldSearchFilter::class:
//                    case MultiFieldSearchFilter::class:
                    case RangeFilter::class:
                        $settings[$property]['searchable'] = true;
                        break;
                }
            }
        }

        // now go through each property, including getting the primary key
        foreach ($reflectionClass->getProperties() as $property) {
            $fieldName = $property->getName();

            foreach ($property->getAttributes() as $attribute) {

//                if ($attribute->getName() === ApiProperty::class) {
//                    dd($attribute->getName(), $attribute->newInstance());
//                }


                if (in_array($attribute->getName(), [MeiliId::class, Id::class])) {
                    $settings[$fieldName]['is_primary'] = true;
                }
                if ($attribute->getName() == ApiProperty::class) {
                    if ($attribute->getArguments()['identifier']??false) {
                        $settings[$fieldName]['is_primary'] = true;
                    }
                }
                if ($attribute->getName() == Facet::class) {
                    $settings[$fieldName]['browsable'] = true;
                }

                if ($attribute->getName() == ApiFilter::class) {
                    $attrInstance = $attribute->newInstance();
                    switch ($filterClass = $attrInstance->filterClass) {
                        case OrderFilter::class:
                            $settings[$fieldName]['sortable'] = true;
                            break;
                        case SearchFilter::class:
                            $settings[$fieldName]['searchable'] = true;
                            break;
                        default:
                            dd("@todo: handle " . $filterClass);
                    }
                }
            }
        }

        // now go through each property, including getting the primary key
        // something's still not right here!  Like setting browsable on getRp.
        foreach ($reflectionClass->getMethods() as $method) {
            $fieldName = $method->getName();
            foreach ($method->getAttributes() as $attribute) {
                if ($attribute->getName() == Facet::class) {
//                if ($attribute->getName() == Groups::class) {
//                    dump($settings, $fieldName, $attribute);
                    $settings[$fieldName]['browsable'] = true;
                }
            }
        }
        // @todo: methods
//        dd($settings);
        return $settings;
    }


    public function sortableFields(?string $class): array
    {

        assert(class_exists($class), $class);
        $reflector = new \ReflectionClass($class);
        foreach ($reflector->getAttributes() as $attribute) {
            $filter = $attribute->getName();
            if (!u($filter)->endsWith('ApiFilter')) {
                continue;
            }
            if (u($filter)->endsWith('OrderFilter')) {
                $orderProperties = $attribute->getArguments()['properties'];
                return $orderProperties;
            }
        }
        return [];
    }

    public function searchableFields(string $class): array
    {
        $reflector = new \ReflectionClass($class);
        $searchableFields = [];
        foreach ($reflector->getAttributes() as $attribute) {
            if (!u($attribute->getName())->endsWith('ApiFilter')) {
                continue;
            }
            $attrInstance = $attribute->newInstance();
            $filter = $attrInstance->filterClass;
//            if (u($filter)->endsWith('MultiFieldSearchFilter')) {
//                $fields = $attribute->getArguments()['properties'];
//                $searchableFields = array_merge($searchableFields,$fields );
//            }
            if (in_array($filter, [RangeFilter::class, SearchFilter::class,
//                MultiFieldSearchFilter::class // @todo: figure this out with meili
            ])) {
                $fields = $attrInstance->properties;
                $searchableFields = array_merge($searchableFields, $fields);
            }
        }
        return $searchableFields;
    }

    public function searchBuilderFields(string $class, array $normalizedColumns): array
    {
        $reflector = new \ReflectionClass($class);
        $columnNumbers = [];
        foreach ($reflector->getAttributes() as $attribute) {

            if (!u($attribute->getName())->endsWith('ApiFilter')) {
                continue;
            }
            $attrInstance = $attribute->newInstance();
            $filter = $attrInstance->filterClass;
// @todo: handle other filters
            if (in_array($filter, [RangeFilter::class, SearchFilter::class])) {
                $searchFields = $attrInstance->properties;
                foreach ($normalizedColumns as $idx => $column) {
                    if (in_array($column->name, $searchFields)) {
                        $columnNumbers[] = $idx;
                    }
                }
            }
        }


        return $columnNumbers;
    }

    public function getNormalizationGroups(string $class): ?array
    {
        static $groupsByClass = [];
        if ($groupsByClass[$class] ?? null) {
            return $groupsByClass[$class];
        }
        $groups = null;
        $meta = $this->entityManager->getMetadataFactory()->getMetadataFor($class);
        // so this can be used by the index updater
        // actually, ApiResource or GetCollection
        $apiRouteAttributes = $meta->getReflectionClass()->getAttributes(ApiResource::class);

        foreach ($apiRouteAttributes as $attribute) {
            $args = $attribute->getArguments();
            // @todo: this could also be inside of the operation!
            if (array_key_exists('normalizationContext', $args)) {
                assert(array_key_exists('groups', $args['normalizationContext']), "Add a groups to " . $meta->getName());
                $groups = $args['normalizationContext']['groups'];
                if (is_string($groups)) {
                    $groups = [$groups];
                }
            }
        }
        $groupsByClass[$class]=$groups;

        return $groups;

    }



}

