<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

/**
 * Selection of document fields (Serializer JSON keys / dotted paths) with optional groups.
 * Order of $fields is preserved and can drive facet order.
 */
final class Fields
{
    /**
     * @param string[]      $fields
     * @param string[]|null $groups
     */
    public function __construct(
        public array $fields = [],
        public ?array $groups = null,
    ) {}

    /** Normalize Fields|array(list)|array{fields?:list,groups?:list} → Fields */
    public static function from(Fields|array $v): self
    {
        if ($v instanceof self) {
            return $v;
        }
        $isAssoc = $v !== [] && array_keys($v) !== range(0, count($v) - 1);
        if ($isAssoc) {
            $fields = array_values((array)($v['fields'] ?? []));
            $groups = isset($v['groups']) ? array_values((array)$v['groups']) : null;
            return new self($fields, $groups);
        }
        return new self(array_values($v), null);
    }
}
