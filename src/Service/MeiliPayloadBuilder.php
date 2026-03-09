<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

use function array_keys;
use function array_unique;
use function array_values;
use function array_filter;
use function array_map;
use function array_key_exists;
use function count;
use function explode;
use function is_array;
use function str_contains;

final class MeiliPayloadBuilder
{
    private readonly PropertyAccessorInterface $propertyAccessor;

    public function __construct(private readonly SerializerInterface $serializer)
    {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->disableExceptionOnInvalidPropertyPath()
            ->getPropertyAccessor();
    }

    /**
     * Build the document payload to send to Meilisearch.
     *
     * IMPORTANT: This method must not conflate:
     *  - what gets indexed (document payload),
     *  - what is filterable/sortable/searchable (index settings),
     *  - what is retrieved/displayed (query/settings).
     *
     * @param array{
     *   groups?: string[]|null,
     *   restrict_groups?: bool|null,   // if true, groups are restrictive; otherwise groups are ignored for baseline payload
     *   fields?: string[]|null,        // BC: forced field paths e.g. ['sku','category','author.name']
     *   force_fields?: string[]|null   // preferred: forced field paths (same semantics as fields)
     * } $persisted
     *
     * @return array<string,mixed>
     */
    public function build(object $entity, array $persisted): array
    {
        $groups = $persisted['groups'] ?? null;
        $restrictGroups = (bool)($persisted['restrict_groups'] ?? $groups);

        // BC: accept either 'force_fields' or legacy 'fields'
        $forceList = $persisted['force_fields'] ?? ($persisted['fields'] ?? []);
        if (!is_array($forceList)) {
            $forceList = [];
        }
        $force = array_values(array_unique(array_map('strval', $forceList)));

        // Pass 1: baseline normalization
        //
        // Default for indexing is "full entity payload".
        // Only apply groups if the caller explicitly says they should be restrictive.
        $ctx1 = [];
        if ($groups && $restrictGroups) {
            $ctx1[AbstractNormalizer::GROUPS] = $groups;
        }

        /** @var array<string,mixed> $doc */
        $doc = $this->serializer->normalize($entity, context: $ctx1);

        if ($force === []) {
            return $doc;
        }

        // Pass 2: for any forced field not already in the doc, read it directly via
        // PropertyAccess. This bypasses serializer group filtering entirely, which is
        // necessary for properties that carry no #[Groups] annotation but are still
        // required in the Meilisearch payload (e.g. a title column added after the
        // initial serialization groups were defined).
        //
        // Dot-notation paths (e.g. "author.name") fall back to the serializer's
        // ATTRIBUTES context with an unrestricted group list.
        $missing = array_values(array_filter(
            $force,
            static fn(string $p) => !self::pathExists($doc, $p)
        ));

        foreach ($missing as $path) {
            if (!str_contains($path, '.')) {
                // Simple top-level field: read directly from the object.
                try {
                    $value = $this->propertyAccessor->getValue($entity, $path);
                } catch (\Throwable) {
                    $value = null;
                }
                $doc[$path] = $value;
            } else {
                // Nested path: use serializer with ATTRIBUTES + all groups unrestricted.
                $tree  = self::toAttributesTree([$path]);
                $ctx2  = [
                    AbstractObjectNormalizer::ATTRIBUTES => $tree,
                    // Empty groups array = "include all groups" in Symfony serializer.
                    AbstractNormalizer::GROUPS           => [],
                ];
                $extra = $this->serializer->normalize($entity, context: $ctx2);
                if (is_array($extra)) {
                    $doc = self::deepMerge($doc, $extra);
                }
            }
        }

        return $doc;
    }

    /**
     * Turn ['a','b.c','b.d.e'] into ['a'=>true,'b'=>['c'=>true,'d'=>['e'=>true]]]
     *
     * @param string[] $paths
     * @return array<string,mixed>
     */
    private static function toAttributesTree(array $paths): array
    {
        $tree = [];
        foreach ($paths as $path) {
            $parts = explode('.', $path);
            $cur = &$tree;

            foreach ($parts as $i => $p) {
                $cur[$p] ??= true;

                if ($i < count($parts) - 1) {
                    if ($cur[$p] === true) {
                        $cur[$p] = [];
                    }
                    $cur = &$cur[$p];
                }
            }

            unset($cur);
        }

        return $tree;
    }

    /** Does a dotted path already exist in $doc? */
    private static function pathExists(array $doc, string $path): bool
    {
        $cur = $doc;
        foreach (explode('.', $path) as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return false;
            }
            $cur = $cur[$p];
        }
        return true;
    }

    /** Recursive array merge that favors right-hand values and merges arrays. */
    private static function deepMerge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = self::deepMerge($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }
}
