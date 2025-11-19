<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Model;

/**
 * Used during import to define a set of data
 */
final class Dataset
{
    public function __construct(
        public readonly string $name,
        public readonly string $url, // list of URLs?
        public readonly string $target,
    ) {}
}
