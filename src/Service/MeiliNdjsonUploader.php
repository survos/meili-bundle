<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MeiliNdjsonUploader
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly MeiliService $meiliService,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $maxPayloadBytes = 10_000_000, // ~10MB per request
        private readonly bool $assertPrimaryKey = true,     // dev guard; keep on by default
    ) {}

    /**
     * Upload docs (arrays) as NDJSON, chunked by bytes.
     *
     * Returns the *last* taskUid (or null if no docs).
     *
     * @param iterable<array<string,mixed>> $docs
     */
    public function uploadDocuments(Indexes $index, iterable $docs, string $primaryKey): ?int
    {
        $lines = [];
        $bytes = 0;
        $sent  = 0;

        $lastTaskUid = null;

        $flush = function () use (&$lines, &$bytes, &$sent, &$lastTaskUid, $index, $primaryKey): void {
            if (!$lines) {
                return;
            }

            $body = implode('', $lines);
            $taskUid = $this->postNdjson($index, $body, $primaryKey);

            $lastTaskUid = $taskUid ?? $lastTaskUid;
            $sent += \count($lines);

            $this->logger?->info('Meili NDJSON batch sent', [
                'index'   => $index->getUid(),
                'docs'    => \count($lines),
                'bytes'   => $bytes,
                'taskUid' => $taskUid,
            ]);

            $lines = [];
            $bytes = 0;
        };

        foreach ($docs as $doc) {
            if (!\is_array($doc)) {
                $this->logger?->warning('Skipping non-array document', [
                    'type' => \get_debug_type($doc),
                ]);
                continue;
            }

            if ($this->assertPrimaryKey) {
                // Keep this guardrail: it turns “PK mismatch” into an immediate developer-visible error.
                assert(
                    \array_key_exists($primaryKey, $doc),
                    "Missing primaryKey '{$primaryKey}' in document keys: " . implode('|', array_keys($doc))
                );
            }

            $line = \json_encode($doc, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . "\n";
            $len  = \strlen($line);

            if ($len > $this->maxPayloadBytes) {
                $flush();
                $taskUid = $this->postNdjson($index, $line, $primaryKey);
                $lastTaskUid = $taskUid ?? $lastTaskUid;
                $sent++;
                continue;
            }

            if ($bytes + $len > $this->maxPayloadBytes && $bytes > 0) {
                $flush();
            }

            $lines[] = $line;
            $bytes  += $len;
        }

        $flush();

        $this->logger?->info('Meili NDJSON upload complete', [
            'index'       => $index->getUid(),
            'sent'        => $sent,
            'lastTaskUid' => $lastTaskUid,
        ]);

        return $lastTaskUid;
    }

    /**
     * Upload a JSONL/NDJSON file (streaming) and return the last taskUid.
     */
    public function uploadJsonlFile(Indexes $index, string $path, ?string $primaryKey = null): ?int
    {
        if (!\is_file($path) || \filesize($path) === 0) {
            return null;
        }

        $reader = new JsonlReader($path);

        $lines = [];
        $bytes = 0;
        $count = 0;

        $lastTaskUid = null;

        $flush = function () use (&$lines, &$bytes, &$count, &$lastTaskUid, $index, $primaryKey): void {
            if (!$lines) {
                return;
            }

            $taskUid = $this->postNdjson($index, implode('', $lines), $primaryKey);

            $lastTaskUid = $taskUid ?? $lastTaskUid;

            $this->logger?->info('Meili NDJSON batch sent', [
                'index'   => $index->getUid(),
                'docs'    => \count($lines),
                'bytes'   => $bytes,
                'taskUid' => $taskUid,
            ]);

            $count += \count($lines);
            $lines = [];
            $bytes = 0;
        };

        foreach ($reader->getIterator() as $row) {
            if (!\is_array($row)) {
                continue;
            }

            if ($primaryKey && $this->assertPrimaryKey) {
                assert(
                    \array_key_exists($primaryKey, $row),
                    "Missing primaryKey '{$primaryKey}' in JSONL row keys: " . implode('|', array_keys($row))
                );
            }

            $line = \json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . "\n";
            $len  = \strlen($line);

            if ($bytes + $len > $this->maxPayloadBytes && $bytes > 0) {
                $flush();
            }

            $lines[] = $line;
            $bytes  += $len;
        }

        $flush();

        $this->logger?->info('Meili NDJSON file upload complete', [
            'index'       => $index->getUid(),
            'sent'        => $count,
            'file'        => $path,
            'lastTaskUid' => $lastTaskUid,
        ]);

        return $lastTaskUid;
    }

    /**
     * POST NDJSON to /documents with optional ?primaryKey=...
     * Returns taskUid when available.
     */
    private function postNdjson(Indexes $index, string $body, ?string $primaryKey): ?int
    {
        $base = rtrim($this->meiliService->getHost(), '/');
        $key  = $this->meiliService->getAdminKey();

        $url  = $base . '/indexes/' . rawurlencode($index->getUid()) . '/documents';
        if ($primaryKey) {
            // This is the critical fix: set PK at first ingestion.
            $url .= '?primaryKey=' . rawurlencode($primaryKey);
        }

        $headers = ['Content-Type' => 'application/x-ndjson'];
        if ($key) {
            $headers['Authorization'] = "Bearer {$key}";
        }

        $resp = $this->http->request('POST', $url, [
            'headers' => $headers,
            'body'    => $body,
        ]);

        $status = $resp->getStatusCode();
        $raw    = $resp->getContent(false);

        if ($status >= 300) {
            throw new \RuntimeException(sprintf(
                "Meili error %d POST %s: %s",
                $status,
                $url,
                $raw
            ));
        }

        // Meilisearch typically returns JSON containing a task identifier.
        // Accept multiple key shapes to be robust across versions/clients.
        $taskUid = null;
        $data = null;

        try {
            $data = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // Some proxies can return empty responses; treat as no task uid.
        }

        if (\is_array($data)) {
            $taskUid = $data['taskUid'] ?? $data['uid'] ?? $data['task_id'] ?? null;
        }

        return \is_int($taskUid) ? $taskUid : null;
    }
}
