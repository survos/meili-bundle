<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class AiChatInput
{
    public function __construct(
        public ?string $label = null,
        public int $order = 0,
    ) {}
}
