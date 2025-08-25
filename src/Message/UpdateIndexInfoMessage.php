<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Message;

/** Ask a worker to fetch stats/settings for one index and update IndexInfo. */
final class UpdateIndexInfoMessage
{
    public function __construct(public string $uid) {}
}
