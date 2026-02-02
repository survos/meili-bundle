<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function array_merge;
use function in_array;
use function json_encode;
use function sprintf;
use function strtolower;
use function trim;

#[AsCommand('meili:settings:update', 'Update Meilisearch index settings from compiler-pass schema')]
final class MeiliSchemaUpdateCommand extends MeiliBaseCommand
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Filter by index name, without prefix')]
        ?string $indexName = null,

        #[Option('Dump settings without applying', name: 'dump')]
        bool $dumpSettings = false,

        #[Option('Wait for task to complete')]
        bool $wait = false,

        #[Option('Apply changes (send updateSettings)')]
        bool $force = false,

        #[Option('Cancel tasks and delete index before applying')]
        bool $reset = false,

        #[Option('Filter by FQCN or short class name')]
        ?string $class = null,

        #[Option('Require a source locale to set indexLanguages')]
        bool $requireLocale = false,

        #[Option('Fail if locale is not in resolver policy')]
        bool $strictLocale = false,
    ): int {
        $this->init();

        $rawSettings = $this->meili->getRawIndexSettings(); // keyed by raw index name
        $bases = $this->resolveTargets($indexName, $class);

        if ($bases === []) {
            $io->warning('No matching indexes.');
            return Command::SUCCESS;
        }

        $sourceLocales = [];

        foreach ($bases as $base) {
            $settings = $rawSettings[$base] ?? null;
            if (!$settings) {
                $io->warning(sprintf('No compiler-pass settings found for "%s".', $base));
                continue;
            }

            $fallbackSource = $this->localeContext?->getDefault() ?? 'en';
            $localePolicy = $this->indexNameResolver->localesFor($base, $fallbackSource);
            $sourceLocale = $localePolicy['source'] ?? $fallbackSource;
            $policyLocales = array_map(static fn(string $loc): string => strtolower($loc), $localePolicy['all']);
            $sourceLocales[$base] = $sourceLocale;

             $schema = $settings['schema'] ?? [];
             // Raw embedders from compiler pass may be shorthand (e.g. ["product"]).
             // Normalize to the exact Meili API shape using the provider.
             $rawEmbedders = $settings['embedders'] ?? [];
             $embedders = [];
             if ($rawEmbedders && $this->embeddersProvider) {
                 // Provider is already constructed with raw embedders from config
                 // and exposes the exact Meili API shape via forMeili().
                 $embedders = $this->embeddersProvider->forMeili();
             }
            $facets = $settings['facets'] ?? [];

             $isMlFor = $this->indexNameResolver->isMultiLingualFor($base, $sourceLocale);
             // Always process base index (null locale); localized indexes come after
             $locales = $isMlFor
                 ? array_merge([null], $localePolicy['all'])
                 : [null];

             foreach ($locales as $locale) {
                 $uid = $this->indexNameResolver->uidFor($base, $locale);

                $io->section(sprintf('Processing %s (locale=%s)', $uid, (string) $locale));

                // Always show what would be applied when very verbose,
                // or when --dump is requested.
                 if ($dumpSettings || $io->isVeryVerbose()) {
                     $dumpPayload = $schema;
                     // Show embedders in dump only for base index
                     if ($locale === null && $embedders) {
                         $dumpPayload['embedders'] = $embedders;
                     }
                     $io->writeln("Schema payload (updateSettings):");
                     $io->writeln(json_encode($dumpPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    if ($io->isDebug()) { // -vvv
                        $io->writeln("\nFacet UI metadata (compiler pass):");
                        $io->writeln(json_encode($facets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }

                if ($dumpSettings) {
                    // dump implies no network calls
                    continue;
                }

                if ($reset) {
                    $this->meili->reset($uid);
                }

                 if (!$force) {
                     $io->warning(sprintf('Use --force to apply settings to %s', $uid));
                     continue;
                 }

                 // No "ensure" semantics: just get endpoint and enqueue updateSettings.
                 // Meilisearch will auto-create index if needed when applying settings.
                 $index = $this->meili->getIndexEndpoint($uid);

                // Apply embedders ONLY on base (non-localized) index
                $payload = $schema;
                if ($locale === null && $embedders) {
                    $payload['embedders'] = $embedders;
                }

                $indexLanguage = $locale ?? $sourceLocale;
                if ($indexLanguage === null || trim($indexLanguage) === '') {
                    if ($requireLocale) {
                        $io->error(sprintf('Missing source locale for %s. Configure locales.source or pass --require-locale=0.', $base));
                        return Command::FAILURE;
                    }
                    $io->warning(sprintf('No source locale for %s; localizedAttributes will not be set.', $base));
                } else {
                    $indexLanguage = strtolower($indexLanguage);
                    if ($policyLocales !== [] && !in_array($indexLanguage, $policyLocales, true)) {
                        $message = sprintf('Locale "%s" not in resolver policy for %s.', $indexLanguage, $base);
                        if ($strictLocale) {
                            $io->error($message);
                            return Command::FAILURE;
                        }
                        $io->warning($message);
                    }

                    $payload['localizedAttributes'] = [[
                        'locales' => [$indexLanguage],
                        'attributePatterns' => ['*'],
                    ]];
                }

                 $task = $index->updateSettings($payload);
                 $taskUid = $task->getTaskUid();

                $io->writeln(sprintf('Dispatched updateSettings taskUid=%s', (string) $taskUid));

                if ($wait) {
                    // Your existing code uses SDK wait() when desired.
                    // Only do this when explicitly requested.
                    try {
                        $task = $task->wait();
                    } catch (\Throwable) {
                        // ignore; waiting is best-effort
                    }
                }
            }
        }

        $this->renderIndexLinks($io, $bases, $sourceLocales);

        return Command::SUCCESS;
    }

    /** @param list<string> $bases */
    private function renderIndexLinks(SymfonyStyle $io, array $bases, array $sourceLocales): void
    {
        if ($bases === []) {
            return;
        }

        foreach ($bases as $baseName) {
            try {
                $params = ['indexName' => $baseName];
                $locale = $sourceLocales[$baseName] ?? null;
                if ($locale !== null && trim($locale) !== '') {
                    $params['_locale'] = $locale;
                }

                $url = $this->urlGenerator->generate('meili_insta', $params, UrlGeneratorInterface::ABSOLUTE_URL);
                $io->writeln(sprintf('Index page: %s', $url));
            } catch (RouteNotFoundException) {
                $io->warning('Route "meili_insta" not found; cannot print index URL.');
                return;
            }
        }
    }
}
