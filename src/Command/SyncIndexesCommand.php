<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Survos\MeiliBundle\Service\IndexSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\UnicodeString;

#[AsCommand('meili:sync-indexes', 'Sync Meilisearch indexes into Doctrine (catalog)')]
final class SyncIndexesCommand
{
    public function __construct(
        private readonly IndexSyncService $sync,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir = '',
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Remove local records not present on the server?', 'prune')] bool $prune = false,
        #[Option('Regex to extract dataset from uid (first capture)', 'dataset-pattern')] ?string $datasetPattern = null,
        #[Option('Regex to extract locale from uid (first capture)', 'locale-pattern')] ?string $localePattern = null,
        #[Option('Generate app-level concrete entity if missing, then exit', 'scaffold')] bool $scaffold = false,
    ): int {
        $io->title('Meilisearch → Doctrine index catalog sync');

        // 1) Preflight: If using mapped superclass, ensure an app concrete exists
        $appEntityFqcn = 'App\\Entity\\IndexInfo';
        $bundleAbstract = 'Survos\\MeiliBundle\\Entity\\AbstractIndexInfo';

        if (class_exists($bundleAbstract) && !class_exists($appEntityFqcn)) {
            if (!$scaffold) {
                $io->warning('App\Entity\IndexInfo not found. You can scaffold it with:');
                $io->writeln('  bin/console meili:sync-indexes --scaffold');
                $io->writeln('After scaffolding, run migrations, then re-run this command.');
                return 2;
            }
            $this->scaffoldAppEntity($io, $appEntityFqcn);
            $io->success('Scaffold created.');
            $io->note('Now run: bin/console doctrine:migrations:diff && bin/console doctrine:migrations:migrate');
            return 0;
        }

        // 2) Preflight: Ensure table exists, otherwise tell user to migrate
        if (!$this->tableExists($io, $this->getTableName($appEntityFqcn))) {
            $io->warning(sprintf('Table "%s" does not exist yet.', $this->getTableName($appEntityFqcn) ?? '(unknown)'));
            $io->writeln('Run: bin/console doctrine:migrations:diff && bin/console doctrine:migrations:migrate');
            return 3;
        }

        $localeResolver = $localePattern
            ? static fn(string $uid): ?string =>
                (preg_match("~{$localePattern}~", $uid, $m) && isset($m[1])) ? $m[1] : null
            : null;

        $datasetResolver = $datasetPattern
            ? static fn(string $uid): ?string =>
                (preg_match("~{$datasetPattern}~", $uid, $m) && isset($m[1])) ? $m[1] : null
            : null;

        $stats = $this->sync->sync($prune, $localeResolver, $datasetResolver);

        $io->success(sprintf(
            'Synced %d indexes (created=%d, updated=%d, unchanged=%d, pruned=%d)',
            $stats['total'], $stats['created'], $stats['updated'], $stats['unchanged'], $stats['pruned']
        ));
        return 0;
    }

    private function scaffoldAppEntity(SymfonyStyle $io, string $fqcn): void
    {
        [$ns, $class] = [substr($fqcn, 0, strrpos($fqcn, '\\')), (new UnicodeString($fqcn))->afterLast('\\')->toString()];
        $path = $this->projectDir . '/src/Entity';
        $file = $path . '/' . $class . '.php';
        $fs = new Filesystem();
        $fs->mkdir($path);

        $code = <<<PHP
        <?php
        declare(strict_types=1);

        namespace {$ns};

        use Doctrine\DBAL\Types\Types;
        use Doctrine\ORM\Mapping as ORM;
        use Survos\MeiliBundle\Entity\AbstractIndexInfo;

        #[ORM\Entity(repositoryClass: \Survos\MeiliBundle\Repository\IndexInfoRepository::class)]
        #[ORM\Table(name: 'meili_index_info')]
        class {$class} extends AbstractIndexInfo
        {
            // Inherit everything from AbstractIndexInfo (uid as PK, public props).
            // You can add project-specific attributes or methods here later.
        }
        PHP;

        $fs->dumpFile($file, $code);
        $io->writeln(sprintf('Created <info>%s</info>', str_replace($this->projectDir.'/', '', $file)));
    }

    private function getTableName(string $fqcn): ?string
    {
        try {
            $class = class_exists($fqcn) ? $fqcn : 'Survos\\MeiliBundle\\Entity\\IndexInfo';
            $meta = $this->em->getClassMetadata($class);
            return $meta->getTableName();
        } catch (\Throwable) {
            return 'meili_index_info';
        }
    }

    private function tableExists(SymfonyStyle $io, ?string $table): bool
    {
        if (!$table) return false;
        try {
            $conn = $this->em->getConnection();
            $sm = $conn->createSchemaManager();
            return $sm->tablesExist([$table]);
        } catch (DbalException $e) {
            $io->error('Could not check schema: ' . $e->getMessage());
            return false;
        }
    }
}
