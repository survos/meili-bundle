<?php

namespace Survos\MeiliBundle\Trait;

use Symfony\Component\PropertyAccess\PropertyAccessor;

trait MeiliDocumentTrait
{


//A document identifier can be of type integer or string, only composed of alphanumeric characters (a-z A-Z 0-9), hyphens (-) and underscores (_), and can not be more than 511 bytes."
    function getMeiliId(): string|int
    {
        $accessor = new PropertyAccessor();
        foreach (['id', 'code'] as $candidate) {
            if ($meiliId = $accessor->getValue($this, $candidate)) {
                return $meiliId;
            }
        }
        throw new \Exception("Meili id not found in id or code, define getMeiliId() method");

    }

    public function cleanupMeiliId(string|int $meiliId): string|int
    {
        if (is_string($meiliId)) {
            $meiliId = str_replace([' ', '.'], '_', $meiliId);
        }
        return $meiliId;
    }

}
