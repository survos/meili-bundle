<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Metadata;

interface MeiliDocumentInterface
{
    public function getMeiliId(): string|int;
}
