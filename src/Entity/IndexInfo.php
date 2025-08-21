<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MeiliBundle\Metadata\Facet;
use Survos\MeiliBundle\Metadata\MeiliDocumentInterface;
use Survos\MeiliBundle\Metadata\MeiliIndex;

/**
 * Catalog row for a Meilisearch index.
 * Public props, minimal hooks; ObjectMapper fills these straight from Meili payloads.
 */
#[ORM\Entity(repositoryClass: \Survos\MeiliBundle\Repository\IndexInfoRepository::class)]
#[ORM\Table(name: 'meili_index_info')]
#[MeiliIndex('meili_index_catalog', pk: 'uid')]
class IndexInfo
{

    /** Primary key configured on the remote index (if any) */
    #[ORM\Column(type: Types::STRING, length: 190, nullable: true)]
    public ?string $primaryKey = null;

    /** Dataset / project / org grouping */
    #[ORM\Column(type: Types::STRING, length: 190, nullable: true)]
    #[Facet]
    public ?string $dataset = null;

    /** Locale facet */
    #[ORM\Column(type: Types::STRING, length: 12, nullable: true)]
    #[Facet]
    public ?string $locale = null;

    /** @var list<string>|null Free-form tags for filtering */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Facet]
    public ?array $tags = null;

    /** Snapshot of document count */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    public int $numDocuments = 0;

    /** Server-side timestamps (Meili) */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $updatedAt = null;

    /** Local bookkeeping */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $lastSyncedAt = null;

    /** Optional: which Meili server/base URL this came from */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $server = null;

    /**
     * Unique on the Meili server, acts as our primary key.
     * Write-once: set in ctor, then only readable (private setter).
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 190)]
    private(set) string $uid;


    public function __construct(string $uid)
    {
        $this->uid = $uid; // write-once via hook (`private set`)
    }
}
