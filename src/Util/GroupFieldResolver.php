<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Util;

use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;

/**
 * Expands Symfony Serializer groups to serialized field names for a class
 * and unions them with explicitly-declared field lists.
 *
 * - Respects #[Groups] on properties/methods
 * - Respects #[SerializedName]
 * - Null-safe for both fields and groups
 * - If '*' is explicitly present in fields, returns ['*'] (Meili "all")
 */
final class GroupFieldResolver
{
    private ?ClassMetadataFactory $factory = null;
    private ?MetadataAwareNameConverter $nameConverter = null;

    /** Cache: class+groupsKey => list of field names */
    private array $cache = [];

    public function __construct(?ClassMetadataFactory $factory = null)
    {
        $this->factory = $factory ?? new ClassMetadataFactory(new AttributeLoader());
        $this->nameConverter = new MetadataAwareNameConverter($this->factory);
    }

    /**
     * Return the union of explicit $fields and the fields implied by $groups.
     *
     * @param class-string|null $class
     * @param string[]|null     $fields
     * @param string[]|null     $groups
     * @return string[]
     */
    public function expandUnion(?string $class, ?array $fields, ?array $groups): array
    {
        $explicit = array_values(array_unique(array_filter($fields ?? [], 'is_string')));
        // If '*' is explicitly requested, that's authoritative for Meilisearch
        if (in_array('*', $explicit, true)) {
            return ['*'];
        }

        $fromGroups = $class ? $this->fieldsFor($class, $groups) : [];

        // Union (stable-ish order: explicit first, then group-derived)
        $union = [];
        foreach ([$explicit, $fromGroups] as $lst) {
            foreach ($lst as $f) {
                if (!in_array($f, $union, true)) {
                    $union[] = $f;
                }
            }
        }
        return $union;
    }

    /**
     * Expand serializer groups to serialized field names.
     *
     * @param class-string|null $class
     * @param string[]|null     $groups
     * @return string[]
     */
    public function fieldsFor(?string $class, ?array $groups = null): array
    {
        if (!$class) {
            return [];
        }

        $groups = array_values(array_unique(array_filter($groups ?? [], 'is_string')));
        if ($groups === []) {
            // No groups: return empty (caller decides whether to use explicit fields only)
            return [];
        }

        $key = $class . '::' . implode('|', $groups);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $meta = $this->factory->getMetadataFor($class);
        $out  = [];

        foreach ($meta->getAttributesMetadata() as $propName => $attrMeta) {
            // Include attribute if it belongs to ANY of the requested groups
            if (array_intersect($groups, $attrMeta->getGroups() ?? [])) {
                // Convert property name to serialized name (honors #[SerializedName])
                $serialized = $this->nameConverter->normalize(
                    $propName,
                    $class,
                    null,
                    ['groups' => $groups]
                );
                $out[] = $serialized ?? $propName;
            }
        }

        // Stabilize + de-dup
        $out = array_values(array_unique($out));
        return $this->cache[$key] = $out;
    }
}
