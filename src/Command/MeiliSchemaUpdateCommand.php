<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use RuntimeException;
use Survos\MeiliBundle\Service\ChatWorkspaceAccessKeyService;
use Survos\MeiliBundle\Service\ChatWorkspaceResolver;
use Survos\MeiliBundle\Service\MeiliServerKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment as TwigEnvironment;

use function array_diff_key;
use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_values;
use function array_unique;
use function count;
use function implode;
use function array_map;
use function array_merge;
use function in_array;
use function json_encode;
use function sprintf;

use Meilisearch\Exceptions\ApiException;
use function strtolower;
use function trim;

#[AsCommand('meili:settings:update', 'Update Meilisearch index settings from compiler-pass schema')]
final class MeiliSchemaUpdateCommand extends MeiliBaseCommand
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ChatWorkspaceAccessKeyService $chatWorkspaceAccessKeyService,
        private readonly ChatWorkspaceResolver $chatWorkspaceResolver,
        private readonly MeiliServerKeyService $meiliServerKeyService,
        private readonly ?TwigEnvironment $twig = null,
        #[Autowire('%survos_meili.chat%')] private readonly array $chatConfig = [],
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

        #[Option('Sync chat workspaces (requires master key)')]
        ?bool $chat = null,

        #[Option('Create and sync managed index keys (requires master key)')]
        bool $keys = false,

        #[Option('Skip pushing embedders (useful when reindexing; semantic search is disabled until re-enabled)')]
        bool $noEmbedders = false,
    ): int {
        $this->init();

        $rawSettings = $this->meili->getRawIndexSettings(); // keyed by raw index name
        $bases = $this->resolveTargets($indexName, $class);

        if ($bases === []) {
            $io->warning($chat === true
                ? 'No matching indexes for schema updates; continuing with chat workspace sync.'
                : 'No matching indexes.'
            );

            if ($chat !== true) {
                return Command::SUCCESS;
            }
        }

        $sourceLocales = [];
        $processedIndexUids = [];

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
             // $rawEmbedders is the list of embedder *names* declared on #[MeiliIndex(embedders: ['product'])].
             // We must filter the global provider map to only those names — otherwise every index
             // gets every globally-configured embedder pushed to it.
             $rawEmbedders = $settings['embedders'] ?? [];
             $embedders = [];
             if ($rawEmbedders && $this->embeddersProvider) {
                 $all = $this->embeddersProvider->forMeili();
                 foreach ($rawEmbedders as $name) {
                     if (isset($all[$name])) {
                         $embedders[$name] = $all[$name];
                     }
                 }
             }
            $facets = $settings['facets'] ?? [];

             $isMlFor = $this->indexNameResolver->isMultiLingualFor($base, $sourceLocale);
             // Always process base index (null locale); localized indexes come after
             $locales = $isMlFor
                 ? array_merge([null], $localePolicy['all'])
                 : [null];

             foreach ($locales as $locale) {
                  $uid = $this->indexNameResolver->uidFor($base, $locale);
                  $processedIndexUids[] = $uid;

                $io->section(sprintf('Processing %s (locale=%s)', $uid, (string) $locale));

                // Always show what would be applied when very verbose,
                // or when --dump is requested.
                 if ($dumpSettings || $io->isVeryVerbose()) {
                      $io->writeln("Schema payload (updateSettings):");
                      $io->writeln(json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    if ($locale === null && $embedders !== []) {
                        $io->writeln('Embedders declared for this index: ' . implode(', ', array_keys($embedders)));
                    }

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

                 // Apply embedders ONLY on base (non-localized) index.
                 // Use updateEmbedders (PATCH) with null tombstones to remove spurious entries —
                 // updateSettings merges embedders and cannot delete them.
                 $payload = $schema;
                 if ($locale === null && $embedders !== []) {
                     if ($noEmbedders) {
                         $io->warning(sprintf(
                             'Skipping embedders for %s (--no-embedders): [%s]. Semantic search is disabled until you re-run without --no-embedders.',
                             $uid,
                             implode(', ', array_keys($embedders))
                         ));
                     } else {
                         // If the index does not exist yet, treat existing embedders as empty —
                         // updateSettings below will auto-create the index.
                         try {
                             $existing = $index->getEmbedders() ?? [];
                         } catch (ApiException $e) {
                             if ((int) $e->getCode() === 404) {
                                 $existing = [];
                                 $io->writeln(sprintf('Index %s not found; will be created by updateSettings.', $uid));
                             } else {
                                 throw $e;
                             }
                         }
                         $toRemove = array_diff_key($existing, $embedders);
                         $embedderPatch = $embedders;
                         // Null-out any embedder on the server that is NOT in the declared list.
                         foreach (array_fill_keys(array_keys($toRemove), null) as $k => $v) {
                             $embedderPatch[$k] = null;
                         }
                         $embTask = $index->updateEmbedders($embedderPatch);
                         $io->writeln(sprintf('Dispatched updateEmbedders taskUid=%s', $embTask->getTaskUid()));
                         if ($wait) {
                             $this->waitAndReport($io, $embTask, 'updateEmbedders', $uid);
                         }
                     }
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
                 $io->writeln(sprintf('Dispatched updateSettings taskUid=%s', $task->getTaskUid()));

                if ($wait) {
                    $this->waitAndReport($io, $task, 'updateSettings', $uid);
                }
            }
        }

        $this->renderIndexLinks($io, $bases, $sourceLocales);

        $uniqueIndexUids = array_values(array_unique($processedIndexUids));
        if ($keys) {
            $this->syncManagedKeys($io, $dumpSettings, $force, $uniqueIndexUids);
        }

        if ($chat === true) {
            $this->syncChat($io, $dumpSettings, $force, $keys);
        }

        return Command::SUCCESS;
    }

    /** @param list<string> $indexUids */
    private function syncManagedKeys(SymfonyStyle $io, bool $dumpSettings, bool $force, array $indexUids): void
    {
        if ($indexUids === []) {
            $io->warning('No indexes available for managed key sync.');

            return;
        }

        $io->section('Managed Meili keys');
        if ($dumpSettings) {
            $io->writeln(sprintf('Would ensure managed keys for indexes: %s', implode(', ', $indexUids)));

            return;
        }

        if (!$force) {
            $io->warning('Use --force together with --keys to create managed Meili keys.');

            return;
        }

        $keys = $this->meiliServerKeyService->ensureServerKeys($indexUids);
        foreach ($keys as $alias => $key) {
            $io->writeln(sprintf(
                '  [%s] %s (uid=%s)',
                $alias,
                $key['created'] ? 'created' : 'verified',
                $key['keyUid']
            ));
        }
    }

    private function waitAndReport(SymfonyStyle $io, object $task, string $label, string $uid): void
    {
        $result = $this->meili->waitForTask($task->getTaskUid());
        $status = $result['status'] ?? 'unknown';
        if ($status === 'succeeded') {
            $io->writeln(sprintf('  [%s] %s succeeded (taskUid=%s)', $uid, $label, $result['uid'] ?? '?'));
        } else {
            $error = $result['error'] ?? [];
            $io->error(sprintf(
                '[%s] %s %s (taskUid=%s): %s — %s',
                $uid,
                $label,
                $status,
                $result['uid'] ?? '?',
                $error['type'] ?? '?',
                $error['message'] ?? '(no message)',
            ));
        }
    }

    private function syncChat(SymfonyStyle $io, bool $dumpSettings, bool $force, bool $createKeys): void
    {
        $client      = $this->meili->getMeiliClient();
        $meiliConfig = $this->meili->getConfig();
        $host        = rtrim($meiliConfig['host'] ?? 'http://127.0.0.1:7700', '/');
        $adminKey    = $meiliConfig['apiKey'] ?? '';
        $workspaces  = $this->chatConfig['workspaces'] ?? [];

        if ($workspaces === []) {
            $io->warning('No chat workspaces configured under survos_meili.chat.workspaces — skipping.');
            return;
        }

        // 1. Enable chatCompletions experimental feature (SDK has no wrapper; call directly)
        $io->section('Chat: experimental feature');
        $featuresUrl = $host . '/experimental-features';
        $response = json_decode(
            file_get_contents($featuresUrl, false, stream_context_create(['http' => [
                'header' => "Authorization: Bearer $adminKey\r\n",
            ]])) ?: '{}',
            true
        );

        if (!empty($response['chatCompletions'])) {
            $io->writeln('chatCompletions already enabled.');
        } elseif ($dumpSettings) {
            $io->writeln('Would send: PATCH /experimental-features {"chatCompletions": true}');
        } elseif ($force) {
            $ctx = stream_context_create(['http' => [
                'method'  => 'PATCH',
                'header'  => "Authorization: Bearer $adminKey\r\nContent-Type: application/json\r\n",
                'content' => json_encode(['chatCompletions' => true]),
            ]]);
            file_get_contents($featuresUrl, false, $ctx);
            $io->writeln('<info>Enabled chatCompletions experimental feature.</info>');
        } else {
            $io->warning('chatCompletions not enabled — use --force to apply.');
        }

        // 2. Sync each workspace template into one actual workspace per index
        foreach ($workspaces as $name => $cfg) {
            $workspaceIndexUids = $this->chatWorkspaceResolver->resolveWorkspaceIndexes($name, $cfg);

            if ($workspaceIndexUids === []) {
                throw new RuntimeException(sprintf('Workspace "%s" is not attached to any indexes.', $name));
            }
            $io->writeln(sprintf('Workspace template "%s" indexes: %s', $name, implode(', ', $workspaceIndexUids)));

            foreach ($workspaceIndexUids as $indexUid) {
                $actualWorkspace = $this->chatWorkspaceResolver->actualWorkspaceName($name, $indexUid);
                $io->section(sprintf('Chat workspace: %s', $actualWorkspace));

                $settings = $this->workspaceSettings($name, $actualWorkspace, $cfg, [$indexUid]);

                if ($dumpSettings) {
                    $io->writeln(json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $io->comment(sprintf('  model "%s" will be sent per-request (not stored in workspace settings)', $cfg['model'] ?? 'gpt-4o-mini'));
                    $debug = $this->chatWorkspaceAccessKeyService->debugInfo($indexUid, $actualWorkspace);
                    $io->comment(sprintf(
                        '  chat key for %s: status=%s source=%s keyUid=%s',
                        $indexUid,
                        $debug['status'],
                        $debug['source'],
                        $debug['keyUid'] ?? '(none)'
                    ));
                    continue;
                }

                if (!$force) {
                    $io->warning(sprintf('Use --force to push workspace "%s" settings.', $actualWorkspace));
                    continue;
                }

                if ($createKeys) {
                    $key = $this->chatWorkspaceAccessKeyService->ensureApiKey($indexUid, $actualWorkspace);
                    $io->writeln(sprintf(
                        '  [%s] %s chat key %s (uid=%s)',
                        $indexUid,
                        $key['created'] ? 'created' : 'verified',
                        $key['created'] ? 'stored in registry' : 'already available',
                        $key['keyUid']
                    ));
                } else {
                    $debug = $this->chatWorkspaceAccessKeyService->debugInfo($indexUid, $actualWorkspace);
                    if ($debug['hasApiKey']) {
                        $io->writeln(sprintf(
                            '  [%s] chat key already in registry (uid=%s)',
                            $indexUid,
                            $debug['keyUid'] ?? '?'
                        ));
                    } else {
                        $io->warning(sprintf(
                            'No registry chat key for %s/%s. Re-run with --keys to create it.',
                            $indexUid,
                            $actualWorkspace
                        ));
                    }
                }

                $applied = $client->chatWorkspace($actualWorkspace)->updateSettings($settings);
                $io->writeln(sprintf(
                    '<info>Pushed settings for workspace "%s"</info> (model per-request: %s)',
                    $actualWorkspace,
                    $cfg['model'] ?? 'gpt-4o-mini'
                ));
                $appliedPrompts = $applied->toArray()['prompts'] ?? [];
                $io->comment(sprintf(
                    '  applied source=%s prompts=%s',
                    $applied->getSource(),
                    implode(', ', array_keys($appliedPrompts))
                ));
            }
        }
    }

    /**
     * Build dynamic prompt strings from the compiled index schema.
     * Collects filterable/sortable attributes across the supplied indexes,
     * and renders each prompt template with that context.
     *
     * @param array<string,mixed> $cfg workspace config
     * @param list<string> $allIndexUids
     * @return array<string,string>
     */
    private function buildDynamicPrompts(string $workspaceName, array $cfg, array $allIndexUids): array
    {
        if ($this->twig === null) {
            return [];
        }

        $rawSettings = $this->meili->getRawIndexSettings();

        // Collect schema info across all indexes covered by this workspace.
        // A workspace may cover multiple indexes; merge their attributes.
        $filterableAttributes = [];
        $sortableAttributes   = [];
        $facets               = [];
        $primaryKey           = 'id';
        $docCount             = 0;
        $firstIndexName       = '';

        foreach ($allIndexUids as $indexUid) {
            // rawSettings is keyed by base name (e.g. product); find the base for this UID.
            $base = null;
            foreach ($rawSettings as $baseName => $_) {
                $resolved = $this->indexNameResolver->uidFor($baseName, null);
                if ($resolved === $indexUid || $baseName === $indexUid) {
                    $base = $baseName;
                    break;
                }
            }

            if ($base === null) {
                continue;
            }

            $s      = $rawSettings[$base];
            $schema = $s['schema'] ?? [];

            // Collect fields excluded from AI context (returnInChat: false).
            $excludedFromChat = [];
            foreach ($s['facets'] ?? [] as $field => $facetCfg) {
                if (!($facetCfg['returnInChat'] ?? true)) {
                    $excludedFromChat[] = $field;
                }
            }

            // Only include filterable attributes that are not excluded from chat context.
            $indexFilterable = array_values(array_filter(
                $schema['filterableAttributes'] ?? [],
                static fn(string $f) => !in_array($f, $excludedFromChat, true)
            ));

            $filterableAttributes = array_values(array_unique(array_merge(
                $filterableAttributes,
                $indexFilterable
            )));
            $sortableAttributes = array_values(array_unique(array_merge(
                $sortableAttributes,
                $schema['sortableAttributes'] ?? []
            )));

            // Merge facet metadata; use returnInChat flag to filter
            foreach ($s['facets'] ?? [] as $field => $facetCfg) {
                if ($facetCfg['returnInChat'] ?? true) {
                    $facets[$field] = $facetCfg;
                }
            }

            if ($firstIndexName === '') {
                $firstIndexName = $indexUid;
                $primaryKey     = $s['primaryKey'] ?? 'id';
            }
        }

        $context = [
            'indexName'            => $firstIndexName,
            'workspaceName'        => $workspaceName,
            'workspaceCfg'         => $cfg,
            'primaryKey'           => $primaryKey,
            'filterableAttributes' => $filterableAttributes,
            'sortableAttributes'   => $sortableAttributes,
            'facets'               => $facets,
            'docCount'             => $docCount,
            'examples'             => $cfg['examples'] ?? [],
        ];

        $prompts = [];

        // Add allIndexUids to context so searchIndexUidParam can pin the list.
        $context['allIndexUids'] = $allIndexUids;

        $templates = [
            'system'               => '@SurvosMeili/chat/system_prompt.txt.twig',
            'searchQParam'         => '@SurvosMeili/chat/search_q_param.txt.twig',
            'searchFilterParam'    => '@SurvosMeili/chat/search_filter_param.txt.twig',
            'searchDescription'    => '@SurvosMeili/chat/search_description.txt.twig',
            'searchIndexUidParam'  => '@SurvosMeili/chat/search_index_uid_param.txt.twig',
        ];

        foreach ($templates as $key => $template) {
            try {
                $rendered = trim($this->twig->render($template, $context));
                if ($rendered !== '') {
                    $prompts[$key] = $rendered;
                }
            } catch (\Throwable $e) {
                // Non-fatal: skip this prompt if template fails
            }
        }

        return $prompts;
    }

    /**
     * @param array<string,mixed> $cfg
     * @param list<string> $indexUids
     * @return array<string,mixed>
     */
    private function workspaceSettings(string $workspaceTemplate, string $workspaceName, array $cfg, array $indexUids): array
    {
        $settings = array_filter([
            'source'       => $cfg['source'] ?? 'openAi',
            'apiKey'       => $cfg['apiKey'] ?? null,
            'baseUrl'      => $cfg['baseUrl'] ?? null,
            'orgId'        => $cfg['orgId'] ?? null,
            'projectId'    => $cfg['projectId'] ?? null,
            'apiVersion'   => $cfg['apiVersion'] ?? null,
            'deploymentId' => $cfg['deploymentId'] ?? null,
        ], static fn($v) => $v !== null && $v !== '');

        if (!isset($settings['apiKey'])) {
            throw new RuntimeException(sprintf(
                'Workspace "%s" has no provider apiKey configured. Set survos_meili.chat.workspaces.%s.apiKey.',
                $workspaceTemplate,
                $workspaceTemplate
            ));
        }

        $staticPrompts = array_filter($cfg['prompts'] ?? [], static fn($v) => $v !== null && $v !== '');
        $dynamicPrompts = $this->buildDynamicPrompts($workspaceName, $cfg, $indexUids);
        $prompts = array_merge($dynamicPrompts, $staticPrompts);
        if ($prompts !== []) {
            $settings['prompts'] = $prompts;
        }

        return $settings;
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
