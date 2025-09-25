<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Entity;

use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndexInfoRepository::class)]
class IndexInfo
{
    #[ORM\Id]
    #[ORM\Column]
    public readonly string $indexName;

    #[ORM\Column]
    public readonly string $pixieCode;

    #[ORM\Column]
    public readonly string $locale;

    #[ORM\Column(type: 'datetime', nullable: true)]
    public ?\DateTime $lastIndexed = null;

    #[ORM\Column(type: 'integer')]
    public int $documentCount = 0;

    #[ORM\Column(nullable: true)]
    public ?string $taskId = null;

    #[ORM\Column(nullable: true)]
    public ?string $batchId = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    public ?string $status = null; // queued, processing, succeeded, failed

    public function __construct(string $indexName, string $pixieCode, string $locale)
    {
        $this->indexName = $indexName;
        $this->pixieCode = $pixieCode;
        $this->locale = $locale;
    }

    public function isComplete(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['queued', 'processing']);
    }
}
