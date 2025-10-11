<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

final class FieldSet
{
    /**
     * @param string[]      $columns
     * @param string[]|null $groups
     */
    public function __construct(
        public readonly array $columns = [],
        public readonly ?array $groups = null,
    ) {}

    /** Accepts FieldSet|array(list)|array{columns?:list,groups?:list} and normalizes to FieldSet. */
    public static function from(FieldSet|array $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        // associative form: ['columns'=>[...], 'groups'=>[...]]
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            $cols = array_values((array)($value['columns'] ?? []));
            $grps = isset($value['groups']) ? array_values((array)$value['groups']) : null;
            return new self($cols, $grps);
        }

        // list form: ['a','b','c']
        return new self(array_values($value), null);
    }
}
