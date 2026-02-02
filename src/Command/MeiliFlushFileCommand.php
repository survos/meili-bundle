<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Service\IndexNameResolver;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Yaml\Yaml;

use function class_exists;
use function filesize;
use function is_array;
use function is_file;
use function is_string;
use function sprintf;
use function trim;

#[AsCommand('meili:flush-file', 'Send an NDJSON/JSONL file to Meilisearch in ~10MB chunks')]
final class MeiliFlushFileCommand
{
    public function __construct(
        private readonly MeiliService $meili,
        private readonly MeiliNdjsonUploader $uploader,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly IndexNameResolver $indexNameResolver,
        private readonly ?DatasetPathsFactoryInterface $pathsFactory = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('index base name (e.g., Books)')] string $name,
        #[Argument('path to .ndjson/.jsonl (optional when --dataset is provided)')] ?string $path = null,
        #[Option('locale suffix for index name (_xx)', 'locale')] ?string $locale = null,
        #[Option('Primary key field (default: id)', name: 'pk')]
        string $primaryKey = 'id',
        #[Option('Dataset key (uses data/20_normalize/obj.jsonl when data-bundle is installed)')]
        ?string $dataset = null,
    ): int {
        if (($path === null || $path === '') && $dataset !== null && $dataset !== '') {
            if ($this->pathsFactory === null) {
                $io->error(sprintf(
                    'Missing path. You passed --dataset=%s, but no DatasetPathsFactoryInterface is registered. ' .
                    'Enable survos/data-bundle or pass an explicit file path.',
                    $dataset
                ));
                return Command::FAILURE;
            }

            $paths = $this->pathsFactory->for($dataset);
            $path = $paths->normalizedObjectPath;
        }

        if ($path === null || $path === '') {
            $io->error('Missing path. Provide the JSONL file path or pass --dataset.');
            return Command::FAILURE;
        }

        if (!is_file($path)) {
            $io->error(sprintf('JSONL file not found: %s', $path));
            return Command::FAILURE;
        }

        $size = filesize($path);
        if ($size === 0) {
            $io->error(sprintf('JSONL file is empty: %s', $path));
            return Command::FAILURE;
        }

        $metaLocale = $this->resolveDatasetLocale($dataset);
        $requestedSource = $locale ?? $metaLocale;
        $localePolicy = $this->indexNameResolver->localesFor($name, $requestedSource ?? 'en');
        $sourceLocale = $requestedSource ?? $localePolicy['source'];
        $isMultilingual = $this->indexNameResolver->isMultiLingualFor($name, $sourceLocale);

        $rawName = $this->indexNameResolver->rawFor($name, $sourceLocale, $isMultilingual);
        $indexUid = $this->indexNameResolver->uidForRaw($rawName);

        $index = $this->meili->getOrCreateIndex($indexUid, autoCreate: true);
        $io->title("Flushing file to $indexUid");
        $io->writeln(sprintf('File: %s (%d bytes)', $path, $size));
        $io->writeln(sprintf('Primary key: %s', $primaryKey));

        $taskUid = $this->uploader->uploadJsonlFile($index, $path, $primaryKey);
        if ($taskUid !== null) {
            $io->writeln(sprintf('Last task UID: %s', (string) $taskUid));
        }
        $this->renderIndexLink($io, $name, $sourceLocale);
        $io->success('Done.');
        return Command::SUCCESS;
    }

    private function renderIndexLink(SymfonyStyle $io, string $baseName, ?string $locale): void
    {
        try {
            $params = ['indexName' => $baseName];
            if ($locale !== null && trim($locale) !== '') {
                $params['_locale'] = $locale;
            }
            $url = $this->urlGenerator->generate('meili_insta', $params, UrlGeneratorInterface::ABSOLUTE_URL);
            $io->writeln(sprintf('Index page: %s', $url));
        } catch (RouteNotFoundException) {
            $io->warning('Route "meili_insta" not found; cannot print index URL.');
        }
    }

    private function resolveDatasetLocale(?string $dataset): ?string
    {
        if ($dataset === null || $dataset === '' || $this->pathsFactory === null) {
            return null;
        }

        if (!class_exists(Yaml::class)) {
            return null;
        }

        $paths = $this->pathsFactory->for($dataset);
        $metaFile = $paths->datasetRoot . '/00_meta/dataset.yaml';
        if (!is_file($metaFile)) {
            return null;
        }

        $raw = Yaml::parseFile($metaFile);
        if (!is_array($raw)) {
            throw new \RuntimeException(sprintf('Invalid YAML in %s (expected mapping)', $metaFile));
        }

        $data = (isset($raw['dataset']) && is_array($raw['dataset'])) ? $raw['dataset'] : $raw;
        $locale = $data['locale']['default'] ?? null;
        if (!is_string($locale)) {
            return null;
        }

        $locale = trim($locale);
        return $locale !== '' ? $locale : null;
    }
}
