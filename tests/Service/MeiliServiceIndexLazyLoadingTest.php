<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\MeiliBundle\Registry\MeiliRegistry;
use Survos\MeiliBundle\Service\IndexNameResolver;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\MeiliServiceConfig;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * meilisearch-php's Client::index()/getIndex() no longer hit the network on their own
 * (see MeilisearchIndexLazyLoadingTest); existence checks that used to rely on getIndex()
 * throwing were silently no-ops. These tests pin down MeiliService::getOrCreateIndex() and
 * getIndexSummaries(), which both had to be rewritten to probe existence with a real call.
 */
#[CoversClass(MeiliService::class)]
final class MeiliServiceIndexLazyLoadingTest extends TestCase
{
    #[Test]
    public function getOrCreateIndexCreatesWhenMissing(): void
    {
        /** @var list<array{string,string}> $requests */
        $requests = [];

        $mock = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            if ('GET' === $method) {
                return self::jsonResponse(['message' => 'not found'], 404);
            }

            // POST /indexes (creation)
            return self::jsonResponse([
                'taskUid' => 1,
                'indexUid' => 'new_books',
                'status' => 'enqueued',
                'type' => 'indexCreation',
                'enqueuedAt' => '2026-01-01T00:00:00Z',
            ], 202);
        });

        $meili = $this->makeMeiliService($mock, 'http://fake-create.test');

        $index = $meili->getOrCreateIndex('new_books', 'isbn', autoCreate: true);

        self::assertSame('new_books', $index->getUid());
        self::assertSame(['GET', 'POST'], array_map(static fn (array $r) => $r[0], $requests));
    }

    #[Test]
    public function getOrCreateIndexReusesWhenPresent(): void
    {
        $requests = [];

        $mock = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            return self::jsonResponse([
                'uid' => 'books',
                'primaryKey' => 'isbn',
                'createdAt' => '2026-01-01T00:00:00Z',
                'updatedAt' => '2026-01-01T00:00:00Z',
            ]);
        });

        $meili = $this->makeMeiliService($mock, 'http://fake-reuse.test');

        $index = $meili->getOrCreateIndex('books', 'isbn', autoCreate: true);

        self::assertSame('books', $index->getUid());
        self::assertSame(['GET'], array_map(static fn (array $r) => $r[0], $requests), 'An existing index must not be re-created.');
    }

    #[Test]
    public function getIndexSummariesReportsExistingAndMissingIndexes(): void
    {
        $mock = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'missing')) {
                return self::jsonResponse(['message' => 'not found'], 404);
            }

            if (str_contains($url, '/stats')) {
                return self::jsonResponse([
                    'numberOfDocuments' => 7,
                    'rawDocumentDbSize' => 100,
                    'avgDocumentSize' => 14,
                    'isIndexing' => false,
                    'numberOfEmbeddings' => 0,
                    'numberOfEmbeddedDocuments' => 0,
                    'fieldDistribution' => [],
                ]);
            }

            return self::jsonResponse([
                'uid' => 'books',
                'primaryKey' => 'isbn',
                'createdAt' => '2026-01-01T00:00:00Z',
                'updatedAt' => '2026-03-01T00:00:00Z',
            ]);
        });

        $meili = $this->makeMeiliService($mock, 'http://fake-summaries.test');

        $rows = $meili->getIndexSummaries(['books', 'missing']);

        self::assertCount(2, $rows);

        $books = $rows[0];
        self::assertTrue($books['exists']);
        self::assertSame('isbn', $books['primaryKey']);
        self::assertSame(7, $books['documentCount']);
        self::assertNull($books['error']);

        $missing = $rows[1];
        self::assertFalse($missing['exists']);
        self::assertNull($missing['error'], 'A plain 404 is an expected "does not exist yet" state, not an error.');
    }

    private function makeMeiliService(HttpClientInterface $httpClient, string $host): MeiliService
    {
        $bag = $this->createStub(\Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $normalizer = $this->createStub(NormalizerInterface::class);

        $nameResolver = new IndexNameResolver(
            new MeiliRegistry([], []),
            $bag,
            new MeiliServiceConfig(),
        );

        return new MeiliService(
            bag: $bag,
            settingsService: new SettingsService($entityManager),
            entityManager: $entityManager,
            normalizer: $normalizer,
            nameResolver: $nameResolver,
            meiliHost: $host,
            symfonyHttpClient: $httpClient,
        );
    }

    private static function jsonResponse(array $data, int $statusCode = 200): MockResponse
    {
        return new MockResponse(
            json_encode($data, JSON_THROW_ON_ERROR),
            ['http_code' => $statusCode, 'response_headers' => ['Content-Type' => 'application/json']]
        );
    }
}
