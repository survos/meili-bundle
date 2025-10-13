<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Meili;

/**
 * Official Meilisearch task statuses.
 * https://www.meilisearch.com/docs/reference/api/tasks#get-task
 */
final class MeiliTaskStatus
{
    public const ENQUEUED = 'enqueued';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    public const ACTIVE = [self::ENQUEUED, self::PROCESSING];
    public const TERMINAL = [self::SUCCEEDED, self::FAILED];
}
