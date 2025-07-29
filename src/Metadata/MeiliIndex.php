<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

use ApiPlatform\Metadata\Exception\InvalidArgumentException;

/**
 * Tags entity class as meili index for doctrine events and interactive index questions
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MeiliIndex
{
    /**
     * @param string|null $indexName defaults to shortClass
     * @param string|null $id defaults to meiliprefix + shortClass
     * @param string|null $alias
     */
    public function __construct(
        public ?string $indexName=null,
        public ?string $id = null,
        public ?string $alias = null,
        public ?string $pk = null,
    ) {
//        if (!is_a($this->filterClass, FilterInterface::class, true)) {
//            throw new InvalidArgumentException(\sprintf('The filter class "%s" does not implement "%s". Did you forget a use statement?', $this->filterClass, FilterInterface::class));
//        }
    }
}
