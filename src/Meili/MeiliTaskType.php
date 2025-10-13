<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Meili;

/**
 * Meilisearch task types (string literals as returned by /tasks).
 * Source: Meilisearch API.
 */
final class MeiliTaskType
{
    public const DOCUMENT_ADDITION_OR_UPDATE = 'documentAdditionOrUpdate';
    public const DOCUMENT_EDITION            = 'documentEdition';
    public const DOCUMENT_DELETION           = 'documentDeletion';

    public const SETTINGS_UPDATE             = 'settingsUpdate';

    public const INDEX_CREATION              = 'indexCreation';
    public const INDEX_DELETION              = 'indexDeletion';
    public const INDEX_UPDATE                = 'indexUpdate';
    public const INDEX_SWAP                  = 'indexSwap';

    public const TASK_CANCELATION            = 'taskCancelation';
    public const TASK_DELETION               = 'taskDeletion';

    public const DUMP_CREATION               = 'dumpCreation';
    public const SNAPSHOT_CREATION           = 'snapshotCreation';

    public const EXPORT                      = 'export';
    public const UPGRADE_DATABASE            = 'upgradeDatabase';

    /** @var string[] All known types */
    public const ALL = [
        self::DOCUMENT_ADDITION_OR_UPDATE,
        self::DOCUMENT_EDITION,
        self::DOCUMENT_DELETION,
        self::SETTINGS_UPDATE,
        self::INDEX_CREATION,
        self::INDEX_DELETION,
        self::INDEX_UPDATE,
        self::INDEX_SWAP,
        self::TASK_CANCELATION,
        self::TASK_DELETION,
        self::DUMP_CREATION,
        self::SNAPSHOT_CREATION,
        self::EXPORT,
        self::UPGRADE_DATABASE,
    ];

    /** @var string[] Document-level mutations */
    public const DOCUMENT_TYPES = [
        self::DOCUMENT_ADDITION_OR_UPDATE,
        self::DOCUMENT_EDITION,
        self::DOCUMENT_DELETION,
    ];

    /** @var string[] Index-level mutations */
    public const INDEX_TYPES = [
        self::INDEX_CREATION,
        self::INDEX_DELETION,
        self::INDEX_UPDATE,
        self::INDEX_SWAP,
        self::SETTINGS_UPDATE,
    ];

    /** @var string[] Administrative/maintenance operations */
    public const ADMIN_TYPES = [
        self::TASK_CANCELATION,
        self::TASK_DELETION,
        self::DUMP_CREATION,
        self::SNAPSHOT_CREATION,
        self::EXPORT,
        self::UPGRADE_DATABASE,
    ];
}
