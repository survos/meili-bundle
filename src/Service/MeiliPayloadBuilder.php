<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

final class MeiliPayloadBuilder
{
    public function __construct(private readonly SerializerInterface $serializer) {}

    /**
     * @param array{
     *   groups?: string[]|null,
     *   force_fields?: string[] // e.g. ['sku','category','author.name']
     * } $settings
     */
    public function build(object $entity, array $persisted): array
    {
        $groups = $persisted['groups'] ?? null;
        $force  = array_values(array_unique($persisted['fields'] ?? []));

        // Pass 1: normal groups-based normalization
        $ctx1 = [];
        $doc = [];
        // if neither groups nor properties, serialize without ctx.
        if (!$groups && !$force) {
            return $this->serializer->normalize($entity, context: $ctx1);
        }
        if ($groups) {
            $ctx1[AbstractNormalizer::GROUPS] = $groups;
            /** @var array<string,mixed> $doc */
            $doc = $this->serializer->normalize($entity, context: $ctx1);
        }
//        dd($doc, $persisted, $force, $ctx1);

        if (!$force) {
            return $doc;
        }

        // Pass 2: only fetch the missing forced fields via ATTRIBUTES tree
        $missing = array_values(array_filter($force, static fn($p) => !self::pathExists($doc, $p)));
        if ($missing) {
            $tree = self::toAttributesTree($missing);
            $ctx2 = [AbstractObjectNormalizer::ATTRIBUTES => $tree];
            /** @var array<string,mixed> $extra */
            $extra = $this->serializer->normalize($entity, context: $ctx2);
            $doc = self::deepMerge($doc, $extra); // union
        }

        return $doc;
    }

    /** Turn ['a','b.c','b.d.e'] into ['a'=>true,'b'=>['c'=>true,'d'=>['e'=>true]]] */
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

    /** Shallow + recursive array merge that favors right-hand values and merges arrays. */
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
