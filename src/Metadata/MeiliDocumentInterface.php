<?php

namespace Survos\MeiliAdminBundle\Metadata;

interface MeiliDocumentInterface
{
    public function getMeiliId(): string|int;
}
