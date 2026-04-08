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
use Doctrine\ORM\EntityManagerInterface;
use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Service\MeiliFieldHeuristic;
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
        private readonly MeiliFieldHeuristic $meiliFieldHeuristic,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly IndexNameResolver $indexNameResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?DatasetPathsFactoryInterface $pathsFactory = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('index base name (e.g., Books)')] ?string $name = null,
        #[Argument('path to .ndjson/.jsonl (optional when --dataset is provided)')] ?string $path = null,
        #[Option('locale suffix for index name (_xx)', 'locale')] ?string $locale = null,
        #[Option('Primary key field (default: id)', name: 'pk')]
        string $primaryKey = 'id',
        #[Option('Dataset key (uses data/20_normalize/obj.jsonl when data-bundle is installed)')]
        ?string $dataset = null,
        #[Option('Wait for the Meilisearch task to finish')]
        bool $wait = false,
        #[Option('Reset index before flush')]
        bool $reset = false,
        #[Option('Apply Meilisearch settings inferred from the dataset/profile before upload')]
        bool $profileSettings = false,
    ): int {
        if (($name === null || $name === '') && $dataset !== null && $dataset !== '') {
            $name = $this->indexNameResolver->baseFromDataset($dataset);
        }

        if ($name === null || $name === '') {
            $io->error('Missing index name. Provide <name> or pass --dataset to derive it.');
            return Command::FAILURE;
        }

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

        if ($reset) {
            $this->meili->purge($indexUid);
        }
        if ($dataset && $primaryKey === 'id') {
            $primaryKey = $this->resolveDatasetPrimaryKey($dataset) ?? $primaryKey;
        }

        $index = $this->meili->getOrCreateIndex($indexUid, primaryKey: $primaryKey, autoCreate: true);
        if ($profileSettings) {
            $profilePath = $this->resolveProfilePath($dataset, $path);
            if ($profilePath === null) {
                $io->warning('Profile settings requested, but no profile file was found.');
            } else {
                $settings = $this->settingsFromProfile($profilePath);
                if ($settings !== []) {
                    $settingsTask = $index->updateSettings($settings);
                    $settingsTaskUid = $settingsTask->getTaskUid();
                    $io->writeln(sprintf('Settings task UID: %s', (string) $settingsTaskUid));
                    if ($wait) {
                        $task = $this->meili->waitForTask((int) $settingsTaskUid);
                        $io->writeln(sprintf('Settings task status: %s', (string) ($task['status'] ?? 'unknown')));
                        if (($task['status'] ?? null) !== 'succeeded') {
                            $io->error(sprintf('Settings task failed: %s', json_encode($task['error'] ?? $task)));
                            return Command::FAILURE;
                        }
                    }
                } else {
                    $io->warning(sprintf('No Meili settings were inferred from %s', $profilePath));
                }
            }
        }
        $io->title("Flushing file to $indexUid");
        $io->writeln(sprintf('File: %s (%d bytes)', $path, $size));
        $io->writeln(sprintf('Primary key: %s', $primaryKey));

        $taskUid = $this->uploader->uploadJsonlFile($index, $path, $primaryKey);
        if ($taskUid !== null) {
            $io->writeln(sprintf('Last task UID: %s', (string) $taskUid));
            if ($wait) {
                $task = $this->meili->waitForTask((int) $taskUid);
                $io->writeln(sprintf('Task status: %s', (string) ($task['status'] ?? 'unknown')));
                if (($task['status'] ?? null) !== 'succeeded') {
                    $io->error(sprintf('Task failed: %s', json_encode($task['error'] ?? $task)));
                    return Command::FAILURE;
                }
            }
        }

        $this->registerIndexInfo($indexUid, $primaryKey, $taskUid);
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

    private function resolveDatasetPrimaryKey(?string $dataset): ?string
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
            return null;
        }

        $data = (isset($raw['dataset']) && is_array($raw['dataset'])) ? $raw['dataset'] : $raw;
        $pk = $data['index']['primary_key'] ?? null;
        return is_string($pk) && $pk !== '' ? $pk : null;
    }

    private function registerIndexInfo(string $indexUid, string $primaryKey, ?int $taskUid): void
    {
        $repo = $this->entityManager->getRepository(IndexInfo::class);
        $entity = $repo->find($indexUid) ?? new IndexInfo($indexUid, $primaryKey, null);

        $entity->primaryKey = $primaryKey;
        $entity->taskId = $taskUid !== null ? (string) $taskUid : null;
        $entity->lastIndexed = new \DateTime();
        $entity->status = 'queued';

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    private function resolveProfilePath(?string $dataset, string $jsonlPath): ?string
    {
        if ($dataset === null || $dataset === '') {
            throw new \RuntimeException('Profile settings require --dataset so canonical stage paths can be resolved.');
        }

        if ($this->pathsFactory === null) {
            throw new \RuntimeException('Profile settings require DatasetPathsFactoryInterface; pass --dataset in an app with import/data paths configured.');
        }

        $paths = $this->pathsFactory->for($dataset);
        $profilePath = $paths->profileObjectPath();

        return is_file($profilePath) ? $profilePath : null;
    }

    private function settingsFromProfile(string $profilePath): array
    {
        $raw = json_decode((string) file_get_contents($profilePath), true);
        if (!is_array($raw) || !is_array($raw['fields'] ?? null)) {
            return [];
        }

        return $this->meiliFieldHeuristic->suggestFromFields($raw['fields'])->toSettingsArray();
    }
}
