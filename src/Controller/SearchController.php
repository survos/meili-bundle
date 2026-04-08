<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Controller;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Survos\MeiliBundle\Service\ChatWorkspaceAccessKeyService;
use Survos\MeiliBundle\Service\ChatWorkspaceResolver;
use Survos\MeiliBundle\Service\CollectionMetadataService;
use Survos\MeiliBundle\Service\MeiliServerKeyService;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Controller\TemplateController;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use function array_merge;
use function count;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function round;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_ends_with;
use function str_starts_with;
use function ucwords;

#[Route('/search')]
class SearchController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/js/')] private string $jsTemplateDir,
        private readonly MeiliService $meiliService,
        private readonly ChatWorkspaceAccessKeyService $chatWorkspaceAccessKeyService,
        private readonly ChatWorkspaceResolver $chatWorkspaceResolver,
        private readonly CollectionMetadataService $collectionMetadataService,
        private readonly MeiliServerKeyService $meiliServerKeyService,
        private readonly RouterInterface $router,
        #[Autowire('%survos_meili.chat%')] private readonly array $chatConfig = [],
        private readonly ?LoggerInterface $logger = null,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * Try to generate the index dashboard URL regardless of the EasyAdmin route prefix.
     * The route is registered as 'meili_index_dashboard' on MeiliAdminController but
     * EasyAdmin prepends the dashboard prefix (e.g. 'meili_admin_meili_index_dashboard').
     */
    private function indexDashboardUrl(string $baseIndexName): ?string
    {
        $candidates = [];
        // Collect all route names and find those ending in 'meili_index_dashboard'
        foreach ($this->router->getRouteCollection()->all() as $name => $_) {
            if (str_ends_with($name, 'meili_index_dashboard')) {
                $candidates[] = $name;
            }
        }
        foreach ($candidates as $name) {
            try {
                return $this->generateUrl($name, ['indexName' => $baseIndexName]);
            } catch (\Throwable) {
                // try next
            }
        }
        return null;
    }

    #[Route('/index/{indexName}', name: 'meili_insta', options: ['expose' => true])]
    #[Route('/index/{indexName}', name: 'meili_insta_locale', options: ['expose' => true])]
    #[Route('/embedder/{indexName}/{embedder}', name: 'meili_insta_embed', options: ['expose' => true])]
    #[Route('/hit/{indexName}/{id}', name: 'meili_hit', options: ['expose' => true])]
    #[Template('@SurvosMeili/insta.html.twig')]
    public function index(
        Request $request,
        string $indexName,
        ?string $embedder = null,
        ?string $q = null,
        bool $useProxy = false,
    ): Response|array {
        // Route parameter is the BASE name (registry key)
        $baseIndexName = $indexName;
        $locale = $request->getLocale();

        // Resolve the actual Meilisearch UID using the new naming resolver logic
        $meiliIndexUid = $this->meiliService->uidForBase($baseIndexName, $locale);

        // Index configuration is base-keyed
        $indexConfig = $this->meiliService->getIndexSetting($baseIndexName)
            ?? [
                'template' => $baseIndexName,
                'primaryKey' => 'id',
                'baseName' => $baseIndexName,
                'facets' => [],
                'instantsearch' => ['routing' => false],
            ];

        // Locale-agnostic template selection
        $templateName = $indexConfig['template'] ?? $baseIndexName;

        // Fetch server settings using the UID
        $index = $this->meiliService->getIndexEndpoint($meiliIndexUid);
        try {
            $settings = $index->getSettings();
        } catch (\Throwable $exception) {
            // useful when index isn't created yet / wrong uid
            throw $exception;
        }

        // If compiled config has no facets, fall back to live filterableAttributes.
        // This covers indexes created outside the app (e.g. dc collections) and
        // the IndexInfo meta-index whose facets come from #[MeiliIndex] but may
        // not be reflected in the compiled indexConfig.
        if (empty($indexConfig['facets'])) {
            $liveFilterable = $settings['filterableAttributes'] ?? [];
            if ($liveFilterable !== []) {
                // Convert flat list to the keyed format insta expects.
                // All keys referenced in the facet block template must be present.
                $facetDefaults = [
                    'collapsed'     => false,
                    'widget'        => 'RefinementList',
                    'searchable'    => null,
                    'searchMode'    => 'contains',
                    'limit'         => null,
                    'showMoreLimit' => null,
                    'lookup'        => null,
                    'sortMode'      => null,
                ];
                $indexConfig['facets'] = array_fill_keys($liveFilterable, $facetDefaults);
            }
        }

        $sorting = [];
        $sorting[] = ['label' => 'Relevance', 'value' => $meiliIndexUid];
        foreach (($settings['sortableAttributes'] ?? []) as $attr) {
            foreach (['asc', 'desc'] as $dir) {
                $sorting[] = [
                    'label' => sprintf('%s %s', $attr, $dir),
                    'value' => sprintf('%s:%s:%s', $meiliIndexUid, $attr, $dir),
                ];
            }
        }

        $stats = $index->stats();

        return [
            // Server / API key
            'server' => $useProxy
                ? $this->router->generate('meili_proxy', [], UrlGeneratorInterface::ABSOLUTE_URL)
                : $this->meiliService->getHost(),
            'apiKey'  => $this->meiliServerKeyService->resolveApiKey($meiliIndexUid),

            // Index identifiers
            'indexName'     => $meiliIndexUid,  // actual Meili UID (what JS uses)
            'baseIndexName' => $baseIndexName,  // base key (registry)

            // Settings / config
            'indexConfig' => $indexConfig,
            'settings'    => $settings,
            'allSettings' => $this->meiliService->getAllSettings(),
            'primaryKey'  => $indexConfig['primaryKey'] ?? 'id',

            // UI state
            'q'              => $q,
            'facets'         => $settings['filterableAttributes'] ?? [],
            'sorting'        => $sorting,
            'endpoint'       => null,
            'embedder'       => $embedder,
            'templateName'   => $templateName,
            'related'        => [],
            'indexStats'     => $stats,
            'multiLingual'   => $this->meiliService->isMultiLingual,
            'translationStyle' => $this->meiliService->getConfig()['translationStyle'] ?? null,

            // Turn off type-as-you-type when an embedder is active
            'searchAsYouType' => $embedder === null,

            // Chat workspace name for this index (null if none configured)
            'chatWorkspace'      => $this->chatWorkspaceResolver->workspaceForIndex($meiliIndexUid),
            'indexDashboardUrl'  => $this->indexDashboardUrl($baseIndexName),
        ];
    }

    /**
     * Render a single hit in detailed view for the detail panel.
     */
    #[Route('/hit/{indexName}/{id}', name: 'meili_hit', options: ['expose' => true])]
    public function hit(Request $request, string $indexName, string $id): Response
    {
        $locale = $request->getLocale();
        $meiliIndexUid = $this->meiliService->uidForBase($indexName, $locale);

        // Fetch the document from Meilisearch
        $index = $this->meiliService->getIndexEndpoint($meiliIndexUid);
        try {
            $document = $index->getDocument($id);
        } catch (\Throwable $e) {
            return new Response('<div class="alert alert-danger">Document not found: ' . $e->getMessage() . '</div>');
        }

        // Get the config to pass the primary key and other settings
        $indexConfig = $this->meiliService->getIndexSetting($indexName)
            ?? ['template' => $indexName, 'primaryKey' => 'id', 'baseName' => $indexName, 'facets' => []];
        $primaryKey = $indexConfig['primaryKey'] ?? 'id';

        // Get the template from TemplateController (reuse existing logic)
        $templateController = $this->container->get(TemplateController::class);
        $templateSource = $templateController->getJsTemplate($indexName, $request->getLocale());

        // Render with detailed=true
        $twig = $this->container->get('twig');
        $template = $twig->createTemplate($templateSource);
        $content = $twig->render($template, [
            'hit' => (array) $document,
            '_config' => array_merge($indexConfig, ['indexName' => $indexName]),
            'view' => ['detailed' => true],
            'globals' => ['_sc_modal' => '@survos/meili-bundle/json'],
        ]);

        return new Response($content);
    }

    /**
     * Chat page: renders the streaming chat UI for an index that has a configured workspace.
     */
    #[Route('/chat/{indexName}/{workspace}', name: 'meili_chat', options: ['expose' => true])]
    #[Template('@SurvosMeili/chat.html.twig')]
    public function chat(
        Request $request,
        string $indexName,
        string $workspace,
    ): Response|array {
        $locale = $request->getLocale();
        $meiliIndexUid = $this->meiliService->uidForBase($indexName, $locale);
        $resolvedWorkspace = $this->chatWorkspaceResolver->resolveRequestedWorkspace($meiliIndexUid, $workspace);
        if ($resolvedWorkspace === null) {
            throw $this->createNotFoundException(sprintf('Chat workspace "%s" is not configured for index "%s".', $workspace, $meiliIndexUid));
        }
        $workspaceTemplate = $this->workspaceTemplateForResolvedWorkspace($resolvedWorkspace, $meiliIndexUid);
        $workspaceCfg = $workspaceTemplate !== null ? ($this->chatConfig['workspaces'][$workspaceTemplate] ?? null) : null;

        if ($workspaceCfg === null) {
            throw $this->createNotFoundException(sprintf('Chat workspace "%s" is not configured.', $workspace));
        }

        // Fetch live prompts from the server so the debug panel shows exactly
        // what Meilisearch is using — not stale local config.
        $livePrompts = null;
        try {
            $settings = $this->meiliService->getMeiliClient()
                ->chatWorkspace($resolvedWorkspace)
                ->getSettings();
            $livePrompts = $settings->toArray()['prompts'] ?? null;
        } catch (\Throwable) {
            // Non-fatal: debug panel will show a warning instead
        }

        $indexSettings = $this->meiliService->getIndexSetting($indexName) ?? [];
        $meiliConfig   = $this->meiliService->getConfig();
        $primaryKey    = (string) ($indexSettings['primaryKey'] ?? 'id');
        $workspaceIndexUids = $workspaceTemplate !== null
            ? $this->chatWorkspaceResolver->resolveWorkspaceIndexes($workspaceTemplate, $workspaceCfg)
            : [];
        $allowTemplateCopy = count($workspaceIndexUids) <= 1;
        $liveIndexSettings = null;
        $schemaUrl     = $this->schemaUrlForRequest($request, $workspaceCfg);
        $collectionOverview = null;
        $chatApiKey = $this->chatWorkspaceAccessKeyService->resolveApiKey($meiliIndexUid, $resolvedWorkspace);
        $clientApiKey = $this->meiliServerKeyService->resolveApiKey($meiliIndexUid);
        $chatKeyDebug = $this->chatWorkspaceAccessKeyService->debugInfo($meiliIndexUid, $resolvedWorkspace);

        try {
            $liveIndexSettings = $this->meiliService->getIndexEndpoint($meiliIndexUid)->getSettings();
        } catch (\Throwable $exception) {
            $this->logger?->warning('Unable to fetch live index settings for chat debug.', [
                'index' => $meiliIndexUid,
                'exception' => $exception,
            ]);
        }

        try {
            $this->collectionMetadataService->warmup($meiliIndexUid, $schemaUrl);
            $collectionOverview = json_decode($this->collectionMetadataService->describeCollection($meiliIndexUid, $schemaUrl), true);
        } catch (\Throwable $exception) {
            $this->logger?->warning('Unable to warm collection metadata cache.', [
                'index' => $meiliIndexUid,
                'schemaUrl' => $schemaUrl,
                'exception' => $exception,
            ]);
        }

        return [
            'indexName'          => $meiliIndexUid,
            'baseIndexName'      => $indexName,
            'workspace'          => $resolvedWorkspace,
            'chatWorkspace'      => $resolvedWorkspace,
            'workspaceTemplate'  => $workspaceTemplate,
            'workspaceCfg'       => $workspaceCfg,
            'schemaUrl'          => $schemaUrl,
            'collectionOverview' => is_array($collectionOverview) ? $collectionOverview : null,
            'welcomeExamples'    => $this->welcomeExamples($workspaceCfg, $indexName, $indexSettings, $allowTemplateCopy),
            'curatorName'        => $this->curatorName($workspaceCfg, $indexName, $allowTemplateCopy),
            'initialQuery'       => $request->query->getString('q'),
            'embedders'          => $indexSettings['embedders'] ?? [],
            'indexSettings'      => $indexSettings,
            'liveIndexSettings'  => is_array($liveIndexSettings) ? $liveIndexSettings : null,
            'livePrompts'        => $livePrompts,
            'templateUrl'        => $this->generateUrl('meili_template', ['templateName' => $indexName]),
            'indexDashboardUrl'  => $this->indexDashboardUrl($indexName),
            'meiliHost'          => rtrim($meiliConfig['host'] ?? 'http://localhost:7700', '/'),
            'meiliApiKey'        => $clientApiKey,
            'chatApiKeyPresent'  => $chatApiKey !== null,
            'chatKeyDebug'       => $chatKeyDebug,
            'streamUrl'          => $this->generateUrl('meili_chat_stream', [
                'indexName' => $indexName,
                'workspace' => $resolvedWorkspace,
            ]),
            'primaryKey'         => $primaryKey,
        ];
    }

    /**
     * SSE endpoint: proxies a message to Meilisearch chatCompletions and streams the response.
     * Add ?debug=1 for non-streaming mode (easier to debug).
     */
    #[Route('/chat/{indexName}/{workspace}/stream', name: 'meili_chat_stream', methods: ['POST'], options: ['expose' => true])]
    public function chatStream(
        Request $request,
        string $indexName,
        string $workspace,
    ): Response {
        $resolvedWorkspace = $this->chatWorkspaceResolver->resolveRequestedWorkspace($this->meiliService->uidForBase($indexName, $request->getLocale()), $workspace);
        $workspaceTemplate = $resolvedWorkspace !== null
            ? $this->workspaceTemplateForResolvedWorkspace($resolvedWorkspace, $this->meiliService->uidForBase($indexName, $request->getLocale()))
            : null;
        $workspaceCfg = $workspaceTemplate !== null ? ($this->chatConfig['workspaces'][$workspaceTemplate] ?? null) : null;
        if ($workspaceCfg === null) {
            throw $this->createNotFoundException(sprintf('Chat workspace "%s" is not configured.', $workspace));
        }

        $locale = $request->getLocale();
        $meiliIndexUid = $this->meiliService->uidForBase($indexName, $locale);
        if ($resolvedWorkspace === null) {
            throw $this->createNotFoundException(sprintf('Chat workspace "%s" is not configured for index "%s".', $workspace, $meiliIndexUid));
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $messages = $body['messages'] ?? [];
        $schemaUrl = $this->schemaUrlForRequest($request, $workspaceCfg, $body['schemaUrl'] ?? null);

        $config = $this->meiliService->getConfig();
        $host   = rtrim($config['host'] ?? 'http://localhost:7700', '/');
        $apiKey = $this->chatWorkspaceAccessKeyService->resolveApiKey($meiliIndexUid, $resolvedWorkspace);
        if ($apiKey === null) {
            throw new RuntimeException(sprintf(
                'No registry-backed chat API key exists for index "%s" and workspace "%s". Run "bin/console meili:settings:update --chat --keys --force" first.',
                $meiliIndexUid,
                $resolvedWorkspace
            ));
        }
        $model  = $workspaceCfg['model'] ?? 'gpt-4o-mini';

        // Debug mode: non-streaming for easier debugging
        $debugMode = $request->query->getBoolean('debug') || $request->query->getBoolean('no-stream');

        // Prepend a system message that pins the index UID for this session.
        $indexPinMessage = [
            'role'    => 'system',
            'content' => sprintf(
                'You MUST search only the index "%s". Do not search any other index under any circumstances.',
                $meiliIndexUid
            ),
        ];
        $messages = array_merge([$indexPinMessage], $messages);

        try {
            $collectionOverview = $this->collectionMetadataService->describeCollection($meiliIndexUid, $schemaUrl);
            $messages = array_merge([[
                'role' => 'system',
                'content' => 'Collection overview metadata for this index. Use this context for high-level questions about what kinds of objects exist, how the collection is structured, and what fields mean. Prefer this metadata before listing documents for overview questions. ' . $collectionOverview,
            ]], $messages);
        } catch (\Throwable $exception) {
            $this->logger?->warning('Unable to build collection overview for chat.', [
                'index' => $meiliIndexUid,
                'schemaUrl' => $schemaUrl,
                'exception' => $exception,
            ]);
        }

        $payload = json_encode([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => !$debugMode,
            'tools'    => [
                [
                    'type'     => 'function',
                    'function' => [
                        'name'        => '_meiliSearchProgress',
                        'description' => 'Provides information about the current Meilisearch search operation',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'call_id'             => ['type' => 'string', 'description' => 'The call ID to track the sources of the search'],
                                'function_name'       => ['type' => 'string', 'description' => 'The name of the function being executed'],
                                'function_parameters' => ['type' => 'string', 'description' => 'The parameters of the function, encoded in JSON'],
                            ],
                            'required'             => ['call_id', 'function_name', 'function_parameters'],
                            'additionalProperties' => false,
                        ],
                        'strict' => true,
                    ],
                ],
                [
                    'type'     => 'function',
                    'function' => [
                        'name'        => '_meiliAppendConversationMessage',
                        'description' => 'Append a new message to the conversation based on what happened internally',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'role'         => ['type' => 'string', 'description' => 'The role of the message author'],
                                'content'      => ['type' => 'string', 'description' => 'The message content'],
                                'tool_calls'   => ['type' => ['array', 'null'], 'description' => 'Tool calls made by the assistant', 'items' => ['type' => 'object']],
                                'tool_call_id' => ['type' => ['string', 'null'], 'description' => 'ID of the tool call this message responds to'],
                            ],
                            'required'             => ['role', 'content', 'tool_calls', 'tool_call_id'],
                            'additionalProperties' => false,
                        ],
                        'strict' => true,
                    ],
                ],
                [
                    'type'     => 'function',
                    'function' => [
                        'name'        => '_meiliSearchSources',
                        'description' => 'Provides sources of the search',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'call_id'   => ['type' => 'string', 'description' => 'The call ID matching the search progress event'],
                                'documents' => ['type' => 'object', 'description' => 'The source documents used to generate the response'],
                            ],
                            'required'             => ['call_id', 'documents'],
                            'additionalProperties' => false,
                        ],
                        'strict' => true,
                    ],
                ],
            ],
        ]);

        // Debug mode: non-streaming request
        if ($debugMode) {
            return $this->chatNonStreaming($host, $apiKey, $resolvedWorkspace, $payload, $meiliIndexUid);
        }

        // Build a cache key from the full request payload (workspace + index + messages).
        // Strip the injected index-pin system message so the key is stable across sessions.
        $cacheableMessages = array_filter($messages, static fn($m) => !($m['role'] === 'system' && str_contains($m['content'] ?? '', 'You MUST search only the index')));
        $cacheKey = 'meili_chat_' . hash('xxh128', $resolvedWorkspace . $meiliIndexUid . json_encode(array_values($cacheableMessages)));
        $cache    = $this->cache;
        $logger   = $this->logger;

        // If we have a cached response, replay it immediately without hitting Meilisearch.
        if ($cache !== null) {
            $cached = $cache->getItem($cacheKey);
            if ($cached->isHit()) {
                /** @var list<string> $lines */
                $lines = $cached->get();
                return new StreamedResponse(static function () use ($lines): void {
                    foreach ($lines as $line) {
                        echo $line;
                        flush();
                    }
                }, 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache',
                    'X-Accel-Buffering' => 'no',
                    'X-Meili-Cache'     => 'HIT',
                ]);
            }
        }

        return new StreamedResponse(function () use ($host, $apiKey, $resolvedWorkspace, $payload, $meiliIndexUid, $logger, $cache, $cacheKey): void {
            $url = sprintf('%s/chats/%s/chat/completions', $host, $resolvedWorkspace);

            // ignore_errors: fopen returns the stream even on HTTP 4xx/5xx
            // so we can read Meilisearch's JSON error body instead of failing silently.
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    "Authorization: Bearer $apiKey",
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                ]),
                'content'       => $payload,
                'ignore_errors' => true,
            ]]);

            $stream = fopen($url, 'r', false, $ctx);
            if ($stream === false) {
                $phpError = error_get_last();
                $detail   = sprintf(
                    'Could not connect to Meilisearch at %s — %s',
                    $url,
                    $phpError['message'] ?? 'unknown error'
                );
                $logger?->error($detail);
                echo "data: " . json_encode(['error' => $detail]) . "\n\n";
                flush();
                return;
            }

            // Check HTTP status from stream metadata — catch 4xx/5xx before streaming body
            $meta        = stream_get_meta_data($stream);
            $httpHeaders = $meta['wrapper_data'] ?? [];
            $statusLine  = is_array($httpHeaders) ? ($httpHeaders[0] ?? '') : '';
            if (preg_match('#^HTTP/\S+\s+([45]\d\d)\s+(.+)$#', $statusLine, $m)) {
                $body   = stream_get_contents($stream);
                fclose($stream);
                $decoded = json_decode($body ?: '', true);
                $detail  = sprintf(
                    'Meilisearch error %s %s: %s',
                    $m[1],
                    $m[2],
                    $decoded['message'] ?? $body
                );
                $logger?->error($detail, ['workspace' => $resolvedWorkspace, 'url' => $url]);
                echo "data: " . json_encode(['error' => $detail]) . "\n\n";
                flush();
                return;
            }

            $accumulated = [];
            $hasError    = false;
            $toolCalls   = [];

            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line === false || $line === '') {
                    continue;
                }
                // Log Meilisearch error events so they appear in Symfony profiler logs
                if ($logger !== null && str_starts_with($line, 'data: {')) {
                    $json = json_decode(substr($line, 6), true);
                    if (is_array($json)) {
                        if (($json['type'] ?? '') === 'error') {
                            $err = $json['error'] ?? [];
                            $logger->warning('Meilisearch chat error: [{code}] {message}', [
                                'code'      => $err['code'] ?? '?',
                                'message'   => substr($err['message'] ?? '', 0, 300),
                                'workspace' => $resolvedWorkspace,
                                'index'     => $meiliIndexUid,
                            ]);
                            $hasError = true;
                        }

                        foreach ($json['choices'][0]['delta']['tool_calls'] ?? [] as $tc) {
                            $tcIndex = (int) ($tc['index'] ?? 0);
                            if (!isset($toolCalls[$tcIndex])) {
                                $toolCalls[$tcIndex] = ['name' => null, 'arguments' => ''];
                            }

                            if (isset($tc['function']['name']) && is_string($tc['function']['name'])) {
                                $toolCalls[$tcIndex]['name'] = $tc['function']['name'];
                            }

                            if (isset($tc['function']['arguments']) && is_string($tc['function']['arguments'])) {
                                $toolCalls[$tcIndex]['arguments'] .= $tc['function']['arguments'];
                            }
                        }

                        if (($json['choices'][0]['finish_reason'] ?? null) === 'tool_calls') {
                            foreach ($toolCalls as $toolCall) {
                                $name = $toolCall['name'] ?? null;
                                if (!is_string($name) || $name === '') {
                                    continue;
                                }

                                $arguments = json_decode($toolCall['arguments'], true);
                                if (!is_array($arguments)) {
                                    $logger->warning('Meili chat tool call arguments are not valid JSON.', [
                                        'workspace' => $resolvedWorkspace,
                                        'index' => $meiliIndexUid,
                                        'tool' => $name,
                                    ]);
                                    continue;
                                }

                                if ($name === '_meiliSearchProgress') {
                                    $params = json_decode((string) ($arguments['function_parameters'] ?? ''), true);
                                    $logger->info('Meili chat tool _meiliSearchProgress', [
                                        'workspace' => $resolvedWorkspace,
                                        'index' => $meiliIndexUid,
                                        'queryIndex' => is_array($params) ? ($params['index_uid'] ?? null) : null,
                                        'query' => is_array($params) ? ($params['q'] ?? null) : null,
                                    ]);
                                }

                                if ($name === '_meiliSearchSources') {
                                    $documents = $arguments['documents'] ?? $arguments;
                                    $sourceCount = is_array($documents) ? count($documents) : 0;
                                    $logger->info('Meili chat tool _meiliSearchSources', [
                                        'workspace' => $resolvedWorkspace,
                                        'index' => $meiliIndexUid,
                                        'sourceCount' => $sourceCount,
                                        'primaryKey' => $primaryKey,
                                    ]);
                                }
                            }

                            $toolCalls = [];
                        }
                    }
                }
                $accumulated[] = $line;
                echo $line;
                flush();
            }
            fclose($stream);

            // Persist to cache only on a clean (error-free) response.
            if ($cache !== null && !$hasError && $accumulated !== []) {
                $item = $cache->getItem($cacheKey);
                $item->set($accumulated);
                $item->expiresAfter(3600); // 1 hour
                $cache->save($item);
            }
        }, 200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param array<string,mixed> $workspaceCfg
     */
    private function schemaUrlForRequest(Request $request, array $workspaceCfg, mixed $bodySchemaUrl = null): ?string
    {
        $schemaUrl = $bodySchemaUrl;
        if (!is_string($schemaUrl) || $schemaUrl === '') {
            $schemaUrl = $request->query->getString('schemaUrl', '');
        }
        if ($schemaUrl === '') {
            $schemaUrl = $workspaceCfg['schemaUrl'] ?? null;
        }

        return is_string($schemaUrl) && $schemaUrl !== '' ? $schemaUrl : null;
    }

    /**
     * @param array<string,mixed> $workspaceCfg
     * @return list<string>
     */
    private function welcomeExamples(array $workspaceCfg, string $indexName, array $indexSettings, bool $allowTemplateExamples): array
    {
        $indexScoped = $workspaceCfg['examplesByIndex'][$indexName] ?? [];
        if (is_array($indexScoped)) {
            $normalized = [];
            foreach ($indexScoped as $example) {
                if (is_string($example) && $example !== '') {
                    $normalized[] = $example;
                }
            }
            if ($normalized !== []) {
                return $normalized;
            }
        }

        if ($allowTemplateExamples) {
            $examples = $workspaceCfg['examples'] ?? [];
            if (is_array($examples)) {
                $normalized = [];
                foreach ($examples as $example) {
                    if (is_string($example) && $example !== '') {
                        $normalized[] = $example;
                    }
                }
                if ($normalized !== []) {
                    return $normalized;
                }
            }
        }

        $label = $this->humanizeIndexName($indexName);
        $collection = \strtolower($label);

        $filterable = [];
        foreach ($indexSettings['filterableAttributes'] ?? [] as $field) {
            if (!is_string($field) || $field === '' || $field === 'id') {
                continue;
            }
            $filterable[] = $field;
            if (count($filterable) >= 2) {
                break;
            }
        }

        $examples = [
            sprintf('What kinds of %s are in this collection?', $collection),
            sprintf('Show me 5 notable %s with one sentence each.', $collection),
        ];

        if ($filterable !== []) {
            $examples[] = sprintf(
                'Find %s by %s.',
                $collection,
                implode(' and ', array_map(fn (string $field): string => $this->humanizeField($field), $filterable))
            );
        }

        $examples[] = sprintf('Show a few surprising or unusual %s.', $collection);

        return $examples;
    }

    /**
     * @param array<string,mixed> $workspaceCfg
     */
    private function curatorName(array $workspaceCfg, string $indexName, bool $allowTemplateLabel): string
    {
        $indexScoped = $workspaceCfg['curatorNameByIndex'][$indexName] ?? null;
        if (is_string($indexScoped) && $indexScoped !== '') {
            return $indexScoped;
        }

        $explicit = $workspaceCfg['curatorName'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if ($allowTemplateLabel) {
            $label = $workspaceCfg['label'] ?? $indexName;
            if (!is_string($label) || $label === '') {
                $label = $indexName;
            }
            $label = ucwords(str_replace(['_', '-'], ' ', $label));
            return sprintf('%s Curator', $label);
        }

        $label = $this->humanizeIndexName($indexName);

        return sprintf('%s Curator', $label);
    }

    private function humanizeIndexName(string $indexName): string
    {
        $base = str_starts_with($indexName, 'meili_') ? substr($indexName, 6) : $indexName;
        return ucwords(str_replace(['_', '-'], ' ', $base));
    }

    private function humanizeField(string $field): string
    {
        return \strtolower(str_replace(['_', '-'], ' ', $field));
    }

    private function workspaceTemplateForResolvedWorkspace(string $workspace, string $indexUid): ?string
    {
        foreach ($this->chatWorkspaceResolver->workspaceTemplatesForIndex($indexUid) as $templateWorkspace) {
            if ($workspace === $templateWorkspace || $workspace === $this->chatWorkspaceResolver->actualWorkspaceName($templateWorkspace, $indexUid)) {
                return $templateWorkspace;
            }
        }

        return null;
    }

    #[AdminRoute(path: '/show/liquid/{indexName}', name: 'meili_show_liquid')]
    public function showLiquid(AdminContext $context, string $indexName): Response
    {
        return new Response();
    }

    /**
     * Non-streaming chat for debugging - returns full JSON response.
     */
    private function chatNonStreaming(
        string $host,
        string $apiKey,
        string $workspace,
        string $payload,
        string $indexUid,
    ): Response {
        $url = sprintf('%s/chats/%s/chat/completions', $host, $workspace);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", [
                "Authorization: Bearer $apiKey",
                'Content-Type: application/json',
            ]),
            'content' => $payload,
            'ignore_errors' => true,
        ]]);

        $response = file_get_contents($url, false, $ctx);
        $decoded = json_decode($response, true);

        // Log details for debugging
        $this->logger?->info('Meili chat non-streaming response', [
            'workspace' => $workspace,
            'index' => $indexUid,
            'url' => $url,
            'payload' => json_decode($payload, true),
            'response' => $decoded,
        ]);

        if ($decoded === null) {
            return new Response($response, 500, ['Content-Type' => 'application/json']);
        }

        return new Response(json_encode($decoded, JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json']);
    }
}
