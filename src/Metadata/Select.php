<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

final class Select
{
    /**
     * @param string[]      $columns
     * @param string[]|null $groups
     */
    public function __construct(
        public array $columns = [],
        public ?array $groups = null,
    ) {}

    /** Normalize Select|array(list)|array{columns?:list,groups?:list} → Select */
    public static function from(Select|array $v): self
    {
        if ($v instanceof self) {
            return $v;
        }
        $isAssoc = $v !== [] && array_keys($v) !== range(0, count($v) - 1);
        if ($isAssoc) {
            $cols = array_values((array)($v['columns'] ?? []));
            $grps = isset($v['groups']) ? array_values((array)$v['groups']) : null;
            return new self($cols, $grps);
        }
        return new self(array_values($v), null);
    }
}
