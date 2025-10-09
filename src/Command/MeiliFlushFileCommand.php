<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'meili:flush-file', description: 'Send an NDJSON/JSONL file to Meilisearch in ~10MB chunks')]
final class MeiliFlushFileCommand extends Command
{
    public function __construct(
        private readonly MeiliService $meili,
        private readonly MeiliNdjsonUploader $uploader,
    ) { parent::__construct(); }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('index base name (e.g., Books)')] string $name,
        #[Argument('path to .ndjson/.jsonl')] string $path,
        #[Option('locale suffix for index name (_xx)', 'locale')] ?string $locale = null,
    ): int {
        $indexName = $this->meili->getPrefixedIndexName($name . ($locale ? "_$locale" : ''));
        $index = $this->meili->getOrCreateIndex($indexName, autoCreate: true);
        $io->title("Flushing file to $indexName");
        $this->uploader->uploadFile($index, $path);
        $io->success('Done.');
        return Command::SUCCESS;
    }
}
