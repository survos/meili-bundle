<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Meili;

use DateTimeImmutable;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;

/**
 * DTO for Meilisearch tasks returned by /tasks and SDK calls.
 * Public properties for easy use in  Twig, EA, and debugging.
 * Includes PHP 8.4 property hooks for common flags.
 */
final class Task implements \Stringable
{
    /** Raw payload from Meili for debugging/forwarding */
    public mixed $raw = null;

    public int $taskUid;
    public ?string $indexUid = null;
    public string $status;     // MeiliTaskStatus::*
    public string $type;       // see MeiliTaskType
    public ?DateTimeImmutable $enqueuedAt = null;
    public ?DateTimeImmutable $startedAt = null;
    public ?DateTimeImmutable $finishedAt = null;
    public ?array $error = null;

    public function __construct(array $data)
    {
        $this->updateFromArray($data);
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Update this DTO from a Meili task array (e.g. refreshed poll).
     */
    public function updateFromArray(array $data): self
    {
        $this->raw = $data;

        $this->taskUid   = (int) ($data['taskUid'] ?? $data['uid'] ?? 0);
        $this->indexUid  = $data['indexUid'] ?? $data['indexUid'] ?? null;
        $this->status    = (string) ($data['status'] ?? '');
        $this->type      = (string) ($data['type'] ?? '');

        $this->enqueuedAt = isset($data['enqueuedAt']) ? self::toDt($data['enqueuedAt']) : null;
        $this->startedAt  = isset($data['startedAt'])  ? self::toDt($data['startedAt'])  : null;
        $this->finishedAt = isset($data['finishedAt']) ? self::toDt($data['finishedAt']) : null;

        $this->error = $data['error'] ?? null;

        return $this;
    }

    private static function toDt(string $ts): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($ts);
        } catch (\Throwable) {
            return null;
        }
    }

    // ---- Helper property hooks (PHP 8.4) ----

    public bool $isEnqueued   { get => $this->status === MeiliTaskStatus::ENQUEUED; }
    public bool $isProcessing { get => $this->status === MeiliTaskStatus::PROCESSING; }
    public bool $succeeded    { get => $this->status === MeiliTaskStatus::SUCCEEDED; }
    public bool $failed       { get => $this->status === MeiliTaskStatus::FAILED; }
    public bool $finished     { get => \in_array($this->status, MeiliTaskStatus::TERMINAL, true); }

    /** Milliseconds between startedAt and finishedAt (if available). */
    public ?int $durationMs {
        get => ($this->startedAt && $this->finishedAt)
            ? (int) (($this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000)
            : null;
    }

    /** ISO8601 summary for logs */
    public string $summary {
        get => sprintf(
            '#%d %s (%s) %s',
            $this->taskUid,
            $this->type,
            $this->status,
            $this->indexUid ? "on {$this->indexUid}" : ''
        );
    }

    /** Array form (stable keys) */
    public function toArray(): array
    {
        return [
            'taskUid'    => $this->taskUid,
            'indexUid'   => $this->indexUid,
            'status'     => $this->status,
            'type'       => $this->type,
            'enqueuedAt' => $this->enqueuedAt?->format(DATE_ATOM),
            'startedAt'  => $this->startedAt?->format(DATE_ATOM),
            'finishedAt' => $this->finishedAt?->format(DATE_ATOM),
            'error'      => $this->error,
        ];
    }

    public function __toString()
    {
        return $this->indexUid . '/' . $this->taskUid;
    }
}
