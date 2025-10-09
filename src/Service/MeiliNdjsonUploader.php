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
        private readonly int $maxPayloadBytes = 10_000_000 // ~10MB per request
    ) {}

    /**
     * Upload docs (arrays) as NDJSON, chunked by bytes.
     * @param iterable<array<string,mixed>> $docs
     */
    public function uploadDocuments(Indexes $index, iterable $docs): void
    {
        $lines = [];
        $bytes = 0;
        $sent  = 0;

        $flush = function () use (&$lines, &$bytes, &$sent, $index) {
            if (!$lines) { return; }
            $this->postNdjson($index, implode('', $lines));
            $sent += count($lines);
            $this->logger?->info('Meili NDJSON batch sent', [
                'index' => $index->getUid(),
                'docs'  => count($lines),
                'bytes' => $bytes,
            ]);
            $lines = [];
            $bytes = 0;
        };

        foreach ($docs as $doc) {
            $line = \json_encode($doc, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . "\n";
            $len  = \strlen($line);
            if ($len > $this->maxPayloadBytes) {
                $flush();
                $this->postNdjson($index, $line);
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
            'index' => $index->getUid(),
            'sent'  => $sent,
        ]);
    }

    /** Upload a JSONL/NDJSON file using your JsonlReader (streaming). */
    public function uploadJsonlFile(Indexes $index, string $path): void
    {
        if (!\is_file($path) || \filesize($path) === 0) {
            return;
        }
        $reader = new JsonlReader($path);

        $lines = [];
        $bytes = 0;
        $count = 0;

        $flush = function () use (&$lines, &$bytes, &$count, $index) {
            if (!$lines) { return; }
            $this->postNdjson($index, implode('', $lines));
            $this->logger?->info('Meili NDJSON batch sent', [
                'index' => $index->getUid(),
                'docs'  => count($lines),
                'bytes' => $bytes,
            ]);
            $count += count($lines);
            $lines = [];
            $bytes = 0;
        };

        foreach ($reader->getIterator() as $row) {
            // $row is already decoded array from your bundle
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
            'index' => $index->getUid(),
            'sent'  => $count,
            'file'  => $path,
        ]);
    }

    private function postNdjson(Indexes $index, string $body): void
    {
        $base = rtrim($this->meiliService->getHost(), '/');
        $key  = $this->meiliService->getAdminKey();
        $url  = $base . '/indexes/' . rawurlencode($index->getUid()) . '/documents';

        $headers = ['Content-Type' => 'application/x-ndjson'];
        if ($key) { $headers['Authorization'] = "Bearer {$key}"; }

        $resp = $this->http->request('POST', $url, ['headers' => $headers, 'body' => $body]);
        $status = $resp->getStatusCode();
        if ($status >= 300) {
            throw new \RuntimeException("Meili error $status: ".$resp->getContent(false));
        }
    }
}
