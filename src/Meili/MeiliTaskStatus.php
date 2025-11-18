<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Meili;

use Meilisearch\Contracts\TaskStatus;

/**
 * Official Meilisearch task statuses.
 * https://www.meilisearch.com/docs/reference/api/tasks#get-task
 */
final class MeiliTaskStatus
{
    public const ENQUEUED = TaskStatus::Enqueued->value;
    public const PROCESSING = TaskStatus::Processing->value;
    public const SUCCEEDED = TaskStatus::Succeeded->value;
    public const FAILED = TaskStatus::Failed->value;

    public const ACTIVE = [self::ENQUEUED, self::PROCESSING];
    public const TERMINAL = [self::SUCCEEDED, self::FAILED];
}
