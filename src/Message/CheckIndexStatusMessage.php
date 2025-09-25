<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Message;

class CheckIndexStatusMessage
{
    public function __construct(
        public readonly string $indexName,
        public readonly ?string $taskId = null,
        public readonly ?string $batchId = null,
        public readonly int $attempt = 1,
        public readonly int $maxAttempts = 10
    ) {}
}
