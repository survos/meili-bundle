<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Spool\JsonlSpooler;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'meili:spool:flush', description: 'Send JsonlBundle spools to Meilisearch as NDJSON')]
final class SpoolFlushCommand extends Command
{
    public function __construct(
        private readonly JsonlSpooler $spooler,
        private readonly MeiliService $meili,
        private readonly MeiliNdjsonUploader $uploader,
    ) { parent::__construct(); }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity class (FQCN or ShortName)')] string $class,
        #[Option('locale suffix (_xx)', 'locale')] ?string $locale = null,
        #[Option('docs mode (use *.docs.jsonl)', 'docs')] ?bool $docs = null
    ): int {
        if (!\class_exists($class)) {
            $class = "App\\Entity\\$class";
        }
        if (!\class_exists($class)) {
            $io->error("Unknown entity class: $class");
            return Command::FAILURE;
        }

        $short = (new \ReflectionClass($class))->getShortName();
        $indexName = $this->meili->getPrefixedIndexName($short . ($locale ? "_$locale" : ''));
        $index = $this->meili->getOrCreateIndex($indexName, autoCreate: true);

        $path = $this->spooler->pathFor($class, $locale, (bool)$docs);
        if (!is_file($path) || filesize($path) === 0) {
            $io->warning("No spool to flush: $path");
            return Command::SUCCESS;
        }

        $io->title("Flushing $path → $indexName");
        $this->uploader->uploadJsonlFile($index, $path);
        @unlink($path);
        @unlink($path . '.idx.json'); // drop sidecar index

        $io->success('Done.');
        return Command::SUCCESS;
    }
}
