<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Tests\Sdk;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Pins down the meilisearch-php behavior we got burned by: Client::index()/getIndex() used
 * to eagerly GET /indexes/{uid} (and throw on a missing index); it's now a lazy handle that
 * only hits the network when a real accessor is called, and Index::stats() now returns a
 * typed IndexStats object instead of a raw array. If either regresses, our bundle's
 * existence-check and stats-reading code (MeiliService, IndexSyncService, ...) breaks again.
 */
#[CoversClass(Client::class)]
final class MeilisearchIndexLazyLoadingTest extends TestCase
{
    #[Test]
    public function constructingAnIndexHandleMakesNoRequest(): void
    {
        $client = $this->makeClient(function (): MockResponse {
            self::fail('No HTTP request should have been made yet.');
        });

        $index = $client->index('books');
        $index2 = $client->getIndex('books');

        self::assertSame('books', $index->getUid());
        self::assertSame('books', $index2->getUid());
    }

    #[Test]
    public function firstRealAccessorTriggersExactlyOneRequestAndPopulatesLazily(): void
    {
        $mock = new MockHttpClient(function (string $method, string $url) {
            self::assertSame('GET', $method);
            self::assertStringContainsString('/indexes/books', $url);

            return self::jsonResponse([
                'uid' => 'books',
                'primaryKey' => 'isbn',
                'createdAt' => '2026-01-01T00:00:00Z',
                'updatedAt' => '2026-06-15T12:00:00Z',
            ]);
        });

        $client = $this->makeClientFromSymfonyHttpClient($mock);
        $index = $client->index('books');

        self::assertSame(0, $mock->getRequestsCount());

        self::assertSame('isbn', $index->getPrimaryKey());
        self::assertSame(1, $mock->getRequestsCount());

        // Subsequent calls reuse the already-loaded info; no second request.
        self::assertSame('2026-06-15T12:00:00+00:00', $index->getUpdatedAt()?->format(DATE_ATOM));
        self::assertSame(1, $mock->getRequestsCount());
    }

    #[Test]
    public function missingIndexThrows404OnlyWhenAccessed(): void
    {
        $mock = new MockHttpClient(fn () => self::jsonResponse(
            ['message' => 'Index not found', 'code' => 'index_not_found'],
            404
        ));

        $client = $this->makeClientFromSymfonyHttpClient($mock);
        $index = $client->index('missing');

        self::assertSame(0, $mock->getRequestsCount(), 'Building the handle must not probe existence.');

        try {
            $index->fetchRawInfo();
            self::fail('Expected an ApiException for the missing index.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->httpStatus);
        }
    }

    #[Test]
    public function statsReturnsATypedObjectNotARawArray(): void
    {
        $mock = new MockHttpClient(fn () => self::jsonResponse([
            'numberOfDocuments' => 42,
            'rawDocumentDbSize' => 1024,
            'avgDocumentSize' => 24,
            'isIndexing' => false,
            'numberOfEmbeddings' => 0,
            'numberOfEmbeddedDocuments' => 0,
            'fieldDistribution' => ['title' => 42],
        ]));

        $client = $this->makeClientFromSymfonyHttpClient($mock);
        $stats = $client->index('books')->stats();

        self::assertInstanceOf(\Meilisearch\Contracts\IndexStats::class, $stats);
        self::assertSame(42, $stats->getNumberOfDocuments());
        self::assertFalse(is_array($stats));
    }

    private function makeClient(callable $responseFactory): Client
    {
        return $this->makeClientFromSymfonyHttpClient(new MockHttpClient($responseFactory));
    }

    private function makeClientFromSymfonyHttpClient(MockHttpClient $mock): Client
    {
        $psr18 = new Psr18Client($mock);

        return new Client('http://fake.test', null, $psr18, $psr18, [], $psr18);
    }

    private static function jsonResponse(array $data, int $statusCode = 200): MockResponse
    {
        return new MockResponse(
            json_encode($data, JSON_THROW_ON_ERROR),
            ['http_code' => $statusCode, 'response_headers' => ['Content-Type' => 'application/json']]
        );
    }
}
