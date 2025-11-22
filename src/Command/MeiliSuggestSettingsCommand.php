<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Survos\MeiliBundle\Service\MeiliFieldHeuristic;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read a JsonlBundle-style profile JSON and suggest Meilisearch settings.
 *
 * Example:
 *   bin/console meili:suggest:settings data/movies.profile.json --pretty
 */
#[AsCommand('meili:suggest:settings', 'Suggest Meilisearch settings from a JSONL profile')]
final class MeiliSuggestSettingsCommand
{
    public function __construct(
        private readonly MeiliFieldHeuristic $heuristic,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument] string $profilePath,
        #[Option] bool $pretty = false,
    ): int {
        if (!\is_file($profilePath)) {
            $io->error(sprintf('Profile file "%s" not found.', $profilePath));

            return 1;
        }

        $data = \json_decode(\file_get_contents($profilePath) ?: '', true);
        if (!\is_array($data)) {
            $io->error('Profile JSON is not an object.');

            return 1;
        }

        $fields = $data['fields'] ?? null;
        if (!\is_array($fields)) {
            $io->error('Profile JSON does not contain a "fields" object.');

            return 1;
        }

        $suggestion = $this->heuristic->suggestFromFields($fields);
        $settings = $suggestion->toSettingsArray();

        $flags = \JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        $json = \json_encode($settings, $flags);
        if ($json === false) {
            $io->error('Failed to encode suggested settings to JSON.');

            return 1;
        }

        $io->writeln($json);

        return 0;
    }
}
