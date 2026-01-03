<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

final class MeiliServiceConfig
{
    public function __construct(
        public readonly bool $multiLingual = false,
        public readonly bool $passLocale = false,
        public readonly ?string $prefix = null,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            multiLingual: (bool)($config['multiLingual'] ?? false),
            passLocale:   (bool)($config['passLocale'] ?? false),
            prefix:       isset($config['meiliPrefix']) ? (string)$config['meiliPrefix'] : null,
        );
    }
}
