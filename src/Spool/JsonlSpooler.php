<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Spool;

use Psr\Log\LoggerInterface;
use Survos\JsonlBundle\IO\JsonlWriter;

final class JsonlSpooler
{
    public function __construct(
        private readonly string $spoolDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if (!is_dir($this->spoolDir) && !@mkdir($this->spoolDir, 0775, true) && !is_dir($this->spoolDir)) {
            throw new \RuntimeException("Cannot create spool dir: {$this->spoolDir}");
        }
    }

    public function pathFor(string $entityClass, ?string $locale = null, bool $docs = false): string
    {
        $safe = str_replace('\\', '.', $entityClass);
        $loc  = $locale ? ".$locale" : '';
        $sfx  = $docs ? '.docs.jsonl' : '.ids.jsonl';
        return rtrim($this->spoolDir, '/') . "/$safe$loc$sfx";
    }

    /** @param list<int|string> $ids */
    public function appendIds(string $entityClass, array $ids, ?string $locale = null): string
    {
        $path = $this->pathFor($entityClass, $locale, false);
        $w = JsonlWriter::open($path, createDirs: true);
        foreach ($ids as $id) {
            // Use tokenCode=$id for de-dup across process runs
            $w->write(['id' => $id], (string)$id);
        }
        $w->close();
        $this->logger?->info('Spool IDs', ['file' => $path, 'count' => count($ids)]);
        return $path;
    }

    /** @param iterable<array<string,mixed>> $docs */
    public function appendDocs(string $entityClass, iterable $docs, ?string $locale = null, string $pk = 'id'): string
    {
        $path = $this->pathFor($entityClass, $locale, true);
        $w = JsonlWriter::open($path, createDirs: true);
        $c = 0;
        foreach ($docs as $doc) {
            $token = isset($doc[$pk]) ? (string)$doc[$pk] : null; // optional de-dup
            $w->write($doc, $token);
            $c++;
        }
        $w->close();
        $this->logger?->info('Spool DOCS', ['file' => $path, 'count' => $c]);
        return $path;
    }
}
