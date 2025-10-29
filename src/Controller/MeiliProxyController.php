<?php
// src/Controller/MeiliProxyController.php
namespace Survos\MeiliBundle\Controller;

use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MeiliProxyController extends AbstractController
{
    private const VERSION = 'MeiliProxy v1.2';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly MeiliService $meiliService,
        #[Autowire('%env(MEILI_SERVER)%')] private string $meiliBaseUri,
        #[Autowire('%env(MEILI_SEARCH_KEY)%')] private string $defaultApiKey,
        #[Autowire('%env(default::MEILI_SEMANTIC_THRESHOLD)%')] private ?float $defaultSemanticThreshold = 0.01,
    ) {
        $this->meiliBaseUri = rtrim($this->meiliBaseUri, '/');
        $this->defaultSemanticThreshold ??= 0.01;
    }

    #[Route(
        '/proxy/{path}',
        name: 'meili_proxy',
        requirements: ['path' => '.+'],
        methods: ['GET','POST','PUT','DELETE','PATCH']
    )]
    public function proxy(Request $request, string $path = '/'): Response
    {
        $method  = $request->getMethod();
        $debug   = (string) $request->query->get('debug', '');
        $uri     = "{$this->meiliBaseUri}/{$path}";

        // Always ensure Authorization to Meili
        $headers = $request->headers->all();
        $headers['authorization'] = ['Bearer '.$this->defaultApiKey];
        unset($headers['host']); // hop-by-hop

        // Threshold: header override wins, else env/default
        $headerThreshold   = $request->headers->get('X-Meili-Ranking-Threshold');
        $semanticThreshold = is_numeric($headerThreshold) ? (float)$headerThreshold : $this->defaultSemanticThreshold;

        $rawBody = $request->getContent() ?: '';
        $json    = $rawBody ? json_decode($rawBody, true) : null;

        $this->logger->info(self::VERSION.' start', [
            'method' => $method, 'path' => $path, 'debug' => $debug,
        ]);

        if ($method === 'POST') {
            // ---------- MULTI-SEARCH ----------
            if (preg_match('#/multi-search$#', $path)) {
                $payload = is_array($json) ? $json : ['queries' => []];
                $queries = $payload['queries'] ?? [];

                // Inject threshold on hybrid queries if missing
                foreach ($queries as &$q) {
                    $hasHybrid = isset($q['hybrid']) && is_array($q['hybrid']);
                    if ($hasHybrid && !array_key_exists('rankingScoreThreshold', $q)) {
                        $q['rankingScoreThreshold'] = $semanticThreshold;
                    }
                    // Standard cosmetics
                    $q['highlightPreTag']   = $q['highlightPreTag']   ?? '__ais-highlight__';
                    $q['highlightPostTag']  = $q['highlightPostTag']  ?? '__ais-highlight__';
                    $q['attributesToHighlight'] = $q['attributesToHighlight'] ?? ['*'];
                    $q['showRankingScore']  = true;
                }
                unset($q);

                // DRY-RUN view (for quick verification)
                if ($debug === 'dryRun') {
                    return $this->json([
                        'proxy'   => self::VERSION,
                        'rewrite' => (count($queries) === 1),
                        'queries' => $queries,
                    ]);
                }

                // If exactly one query → rewrite to single-search for true totals
                if (count($queries) === 1) {
                    $q = $queries[0];
                    $index = $q['indexUid'] ?? $q['indexName'] ?? $q['uid'] ?? null;
                    if ($index) {
                        $singleUrl  = "{$this->meiliBaseUri}/indexes/".rawurlencode($index)."/search";
                        $singleBody = $this->singleBodyFromMulti($q);

                        $this->logger->debug(self::VERSION.' rewrite multi→single', [
                            'index' => $index, 'threshold' => $singleBody['rankingScoreThreshold'] ?? null,
                        ]);

                        $resp = $this->client->request('POST', $singleUrl, [
                            'headers' => $headers,
                            'json'    => $singleBody,
                        ]);
                        $data = $resp->toArray(false);

                        // wrap back to multi-shape
                        return $this->json(['results' => [$data]], $resp->getStatusCode(), $this->passThruHeaders($resp));
                    }
                }

                // Otherwise forward fixed multi body
                $resp = $this->client->request('POST', $uri, [
                    'headers' => $headers,
                    'json'    => ['queries' => $queries],
                ]);
                return $this->streamLike($resp);
            }

            // ---------- SINGLE-SEARCH ----------
            if (preg_match('#^indexes/([^/]+)/search$#', $path)) {
                $body = is_array($json) ? $json : [];
                $hasHybrid = isset($body['hybrid']) && is_array($body['hybrid']);

                if ($hasHybrid && !array_key_exists('rankingScoreThreshold', $body)) {
                    $body['rankingScoreThreshold'] = $semanticThreshold;
                }
                $body['showRankingScore']        = true;
                $body['showRankingScoreDetails'] = true;
                $body['highlightPreTag']         = $body['highlightPreTag']  ?? '__ais-highlight__';
                $body['highlightPostTag']        = $body['highlightPostTag'] ?? '__ais-highlight__';
                $body['attributesToHighlight']   = $body['attributesToHighlight'] ?? ['*'];

                if ($debug === 'dryRun') {
                    return $this->json([
                        'proxy' => self::VERSION,
                        'body'  => $body,
                    ]);
                }

                $resp = $this->client->request('POST', $uri, [
                    'headers' => $headers,
                    'json'    => $body,
                ]);
                return $this->streamLike($resp);
            }
        }

        // ---------- DEFAULT PASS-THROUGH ----------
        $options = [
            'headers' => $headers,
            'query'   => $request->query->all(),
        ];
        if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
            $options['body'] = $rawBody;
        }

        $resp = $this->client->request($method, $uri, $options);
        return $this->streamLike($resp);
    }

    /**
     * Convert a multi-search query into a single-search body (normalize pagination to hitsPerPage/page)
     * @param array<string,mixed> $q
     */
    private function singleBodyFromMulti(array $q): array
    {
        $hitsPerPage = (int)($q['limit'] ?? 20);
        $offset      = (int)($q['offset'] ?? 0);
        $page        = ($hitsPerPage > 0) ? intdiv($offset, $hitsPerPage) : 0;

        return array_filter([
            'q'                      => $q['q'] ?? $q['query'] ?? '',
            'hybrid'                 => $q['hybrid'] ?? null,
            'rankingScoreThreshold'  => $q['rankingScoreThreshold'] ?? null,
            'filter'                 => $q['filter'] ?? null,
            'sort'                   => $q['sort'] ?? null,
            'distinct'               => $q['distinct'] ?? null,
            'attributesToSearchOn'   => $q['attributesToSearchOn'] ?? null,
            'matchingStrategy'       => $q['matchingStrategy'] ?? null,
            'facets'                 => $q['facets'] ?? null,
            'attributesToHighlight'  => $q['attributesToHighlight'] ?? ['*'],
            'highlightPreTag'        => $q['highlightPreTag'] ?? '__ais-highlight__',
            'highlightPostTag'       => $q['highlightPostTag'] ?? '__ais-highlight__',
            'hitsPerPage'            => $hitsPerPage,
            'page'                   => $page,
            'showRankingScore'       => true,
            'showRankingScoreDetails'=> true,
        ], static fn($v) => $v !== null);
    }

    private function streamLike(\Symfony\Contracts\HttpClient\ResponseInterface $resp): Response
    {
        $headers = $this->passThruHeaders($resp);
        $status  = $resp->getStatusCode();

        try {
            $contentType = $resp->getHeaders(false)['content-type'][0] ?? 'application/json';
            if (str_contains($contentType, 'application/json')) {
                $data = $resp->getContent(false);
                return new Response($data, $status, ['Content-Type' => $contentType] + $headers);
            }
        } catch (\Throwable) { /* fall through */ }

        $client = $this->client;
        return new StreamedResponse(function () use ($client, $resp) {
            foreach ($client->stream($resp) as $chunk) {
                if ($chunk->isTimeout()) { continue; }
                echo $chunk->getContent(); flush();
            }
        }, $status, $headers);
    }

    /** @return array<string,string> */
    private function passThruHeaders(\Symfony\Contracts\HttpClient\ResponseInterface $resp): array
    {
        $h = $resp->getHeaders(false);
        $out = [];
        foreach (['x-meilisearch-request-id','content-encoding','content-type'] as $k) {
            if (!empty($h[$k][0])) { $out[ucwords($k, '-')]= $h[$k][0]; }
        }
        return $out;
    }
}
