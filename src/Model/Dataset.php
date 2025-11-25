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
        public readonly ?string $url, // list of URLs?
        public readonly string $target, // what the URL fetches
        public string|bool|null $jsonl=null, // output of convert and input of import
        public readonly ?string $afterDownload=null,

    ) {
        // hack, false or blank can mean to stop.
        if (is_null($this->jsonl)) {
            $targetExtension = pathinfo($this->target, PATHINFO_EXTENSION);
            $this->jsonl = str_replace($targetExtension, 'jsonl', $this->target);
        }
    }
}
