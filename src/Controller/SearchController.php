<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Controller;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Service\CollectionMetadataService;
use Survos\MeiliBundle\Service\MeiliService;
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
use function ucwords;

#[Route('/search')]
class SearchController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/js/')] private string $jsTemplateDir,
        private readonly MeiliService $meiliService,
        private readonly CollectionMetadataService $collectionMetadataService,
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

    /**
     * Return the workspace name (if any) that covers the given Meilisearch index UID.
     * Checks compiled index settings (chats: [...] on #[MeiliIndex]) first,
     * then falls back to legacy YAML indexes: [...] in workspace config.
     */
    private function workspaceForIndex(string $meiliIndexUid): ?string
    {
        // Primary: check compiled settings — baseName => settings['chats']
        $allSettings = $this->meiliService->getAllSettings();
        foreach ($allSettings as $baseName => $settings) {
            if (in_array($meiliIndexUid, $settings['chats'] ?? [], true)) {
                // Return the first workspace name that matches
                // (settings['chats'] contains workspace names, not index UIDs)
                return ($settings['chats'][0]) ?? null;
            }
            // Also check if the base name resolves to this UID
            if ($baseName === $meiliIndexUid || str_ends_with($meiliIndexUid, '_' . $baseName)) {
                foreach ($settings['chats'] ?? [] as $workspaceName) {
                    if (isset($this->chatConfig['workspaces'][$workspaceName])) {
                        return $workspaceName;
                    }
                }
            }
        }

        // Fallback: legacy YAML indexes: list
        foreach ($this->chatConfig['workspaces'] ?? [] as $name => $cfg) {
            if (in_array($meiliIndexUid, $cfg['indexes'] ?? [], true)) {
                return $name;
            }
        }

        // Default: if any workspace is configured, use the first one.
        // Every index gets chat unless explicitly excluded.
        $workspaces = $this->chatConfig['workspaces'] ?? [];
        if ($workspaces !== []) {
            return array_key_first($workspaces);
        }

        return null;
    }

    #[Route('/index/{indexName}', name: 'meili_insta', options: ['expose' => true])]
    #[Route('/index/{indexName}', name: 'meili_insta_locale', options: ['expose' => true])]
    #[Route('/embedder/{indexName}/{embedder}', name: 'meili_insta_embed', options: ['expose' => true])]
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
            ?? ['template' => $baseIndexName, 'primaryKey' => 'id', 'baseName' => $baseIndexName, 'facets' => []];

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
            'apiKey'  => $this->meiliService->getPublicApiKey(),

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
            'chatWorkspace'      => $this->workspaceForIndex($meiliIndexUid),
            'indexDashboardUrl'  => $this->indexDashboardUrl($baseIndexName),
        ];
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
        $workspaceCfg = $this->chatConfig['workspaces'][$workspace] ?? null;

        if ($workspaceCfg === null) {
            throw $this->createNotFoundException(sprintf('Chat workspace "%s" is not configured.', $workspace));
        }

        // Fetch live prompts from the server so the debug panel shows exactly
        // what Meilisearch is using — not stale local config.
        $livePrompts = null;
        try {
            $settings = $this->meiliService->getMeiliClient()
                ->chatWorkspace($workspace)
                ->getSettings();
            $livePrompts = $settings->toArray()['prompts'] ?? null;
        } catch (\Throwable) {
            // Non-fatal: debug panel will show a warning instead
        }

        $indexSettings = $this->meiliService->getIndexSetting($indexName) ?? [];
        $meiliConfig   = $this->meiliService->getConfig();
        $primaryKey    = (string) ($indexSettings['primaryKey'] ?? 'id');
        $schemaUrl     = $this->schemaUrlForRequest($request, $workspaceCfg);
        $collectionOverview = null;

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
            'workspace'          => $workspace,
            'chatWorkspace'      => $workspace,
            'workspaceCfg'       => $workspaceCfg,
            'schemaUrl'          => $schemaUrl,
            'collectionOverview' => is_array($collectionOverview) ? $collectionOverview : null,
            'welcomeExamples'    => $this->welcomeExamples($workspaceCfg),
            'curatorName'        => $this->curatorName($workspaceCfg, $indexName),
            'embedders'          => $indexSettings['embedders'] ?? [],
            'livePrompts'        => $livePrompts,
            'templateUrl'        => $this->generateUrl('meili_template', ['templateName' => $indexName]),
            'indexDashboardUrl'  => $this->indexDashboardUrl($indexName),
            'meiliHost'          => rtrim($meiliConfig['host'] ?? 'http://localhost:7700', '/'),
            'meiliApiKey'        => $meiliConfig['apiKey'] ?? '',
            'streamUrl'          => $this->generateUrl('meili_chat_stream', [
                'indexName' => $indexName,
                'workspace' => $workspace,
            ]),
            'primaryKey'         => $primaryKey,
        ];
    }

    /**
     * SSE endpoint: proxies a message to Meilisearch chatCompletions and streams the response.
     */
    #[Route('/chat/{indexName}/{workspace}/stream', name: 'meili_chat_stream', methods: ['POST'], options: ['expose' => true])]
    public function chatStream(
        Request $request,
        string $indexName,
        string $workspace,
    ): StreamedResponse {
        $workspaceCfg = $this->chatConfig['workspaces'][$workspace] ?? null;
        if ($workspaceCfg === null) {
            throw $this->createNotFoundException(sprintf('Chat workspace "%s" is not configured.', $workspace));
        }

        $locale = $request->getLocale();
        $meiliIndexUid = $this->meiliService->uidForBase($indexName, $locale);

        $body = json_decode($request->getContent(), true) ?? [];
        $messages = $body['messages'] ?? [];
        $schemaUrl = $this->schemaUrlForRequest($request, $workspaceCfg, $body['schemaUrl'] ?? null);

        $config = $this->meiliService->getConfig();
        $host   = rtrim($config['host'] ?? 'http://localhost:7700', '/');
        $apiKey = $config['apiKey'] ?? '';
        $model  = $workspaceCfg['model'] ?? 'gpt-4o-mini';

        // Prepend a system message that pins the index UID for this session.
        // The workspace is shared across all indexes, so without this the AI
        // may search any available index (e.g. meili_product) regardless of
        // which index page the user came from.
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
            'stream'   => true,
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

        // Build a cache key from the full request payload (workspace + index + messages).
        // Strip the injected index-pin system message so the key is stable across sessions.
        $cacheableMessages = array_filter($messages, static fn($m) => !($m['role'] === 'system' && str_contains($m['content'] ?? '', 'You MUST search only the index')));
        $cacheKey = 'meili_chat_' . hash('xxh128', $workspace . $meiliIndexUid . json_encode(array_values($cacheableMessages)));
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

        return new StreamedResponse(function () use ($host, $apiKey, $workspace, $payload, $meiliIndexUid, $logger, $cache, $cacheKey): void {
            $url = sprintf('%s/chats/%s/chat/completions', $host, $workspace);

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
                $logger?->error($detail, ['workspace' => $workspace, 'url' => $url]);
                echo "data: " . json_encode(['error' => $detail]) . "\n\n";
                flush();
                return;
            }

            $accumulated = [];
            $hasError    = false;

            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line === false || $line === '') {
                    continue;
                }
                // Log Meilisearch error events so they appear in Symfony profiler logs
                if ($logger !== null && str_starts_with($line, 'data: {')) {
                    $json = json_decode(substr($line, 6), true);
                    if (is_array($json) && ($json['type'] ?? '') === 'error') {
                        $err = $json['error'] ?? [];
                        $logger->warning('Meilisearch chat error: [{code}] {message}', [
                            'code'      => $err['code'] ?? '?',
                            'message'   => substr($err['message'] ?? '', 0, 300),
                            'workspace' => $workspace,
                            'index'     => $meiliIndexUid,
                        ]);
                        $hasError = true;
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
    private function welcomeExamples(array $workspaceCfg): array
    {
        $examples = $workspaceCfg['examples'] ?? [];
        if (!is_array($examples)) {
            return [];
        }

        $normalized = [];
        foreach ($examples as $example) {
            if (is_string($example) && $example !== '') {
                $normalized[] = $example;
            }
        }

        if ($normalized !== []) {
            return $normalized;
        }

        return [
            'Tell me about this collection.',
            'What kinds of objects do you have, and how are they categorized?',
            'Give me a list of 7 items that a 5th-grade boy would be interested in and a 1-sentence description why.',
            'Show me a few surprising or unusual items from the collection.',
        ];
    }

    /**
     * @param array<string,mixed> $workspaceCfg
     */
    private function curatorName(array $workspaceCfg, string $indexName): string
    {
        $label = $workspaceCfg['label'] ?? $indexName;
        if (!is_string($label) || $label === '') {
            $label = $indexName;
        }

        $label = ucwords(str_replace(['_', '-'], ' ', $label));

        return sprintf('%s Curator', $label);
    }

    #[AdminRoute(path: '/show/liquid/{indexName}', name: 'meili_show_liquid')]
    public function showLiquid(AdminContext $context, string $indexName): Response
    {
        return new Response();
    }
}
