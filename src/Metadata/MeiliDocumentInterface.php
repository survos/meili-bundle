<?php

namespace Survos\MeiliBundle\Metadata;

interface MeiliDocumentInterface
{
    public function getMeiliId(): string|int;
}
