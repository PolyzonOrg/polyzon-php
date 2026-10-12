<?php

declare(strict_types=1);

namespace Polyzon\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Polyzon\Exception\ApiException;
use Polyzon\Exception\ApiTimeoutException;
use Polyzon\Exception\AuthenticationException;
use Polyzon\Exception\GoneException;
use Polyzon\Exception\InvalidResponseException;
use Polyzon\Exception\NotFoundException;
use Polyzon\Exception\PermissionDeniedException;
use Polyzon\Exception\QuotaExceededException;
use Polyzon\Internal\Hooks;
use Polyzon\ModelRef;
use Polyzon\ModelResult;

final class BatchTest extends TestCase
{
    /**
     * @return list<ModelRef>
     */
    private static function refs(int $count, string $prefix = 'tv'): array
    {
        return array_map(static fn(int $i): ModelRef => new ModelRef('thingiverse', "{$prefix}-{$i}"), range(1, $count));
    }

    /**
     * @param list<ModelResult> $results
     *
     * @return list<string>
     */
    private static function ids(array $results): array
    {
        return array_map(static fn(ModelResult $result): string => $result->ref->id, $results);
    }

    public function testResultsComeInInputOrderWithMetadata(): void
    {
        // Earlier refs answer slower, so they finish last.
        $routes = [];
        foreach (range(1, 6) as $i) {
            $routes["/v1/models/thingiverse/tv-{$i}"] = [['ok' => true, 'sleep' => (7 - $i) * 60]];
        }
        $url = self::scenario([], $routes);
        $results = self::client($url)->getModels(self::refs(6), concurrency: 6, lang: 'en');

        self::assertSame(['tv-1', 'tv-2', 'tv-3', 'tv-4', 'tv-5', 'tv-6'], self::ids($results));
        foreach ($results as $result) {
            self::assertTrue($result->isOk());
            self::assertNull($result->error);
            self::assertNotNull($result->model);
            self::assertSame($result->ref->id, $result->model->id);
            self::assertSame('req-123', $result->model->meta->requestId);
        }
        foreach (self::requests($url) as $request) {
            self::assertSame(['lang' => 'en'], $request['query']);
            self::assertSame('Bearer ' . self::TOKEN, $request['headers']['authorization']);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function concurrencies(): iterable
    {
        yield 'one' => [1];
        yield 'three' => [3];
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private static function peak(array $entries): int
    {
        return max(array_map(static fn(array $entry): int => \is_int($entry['inflight'] ?? null) ? $entry['inflight'] : 0, [...$entries, []]));
    }

    #[DataProvider('concurrencies')]
    public function testRequestsRunInParallelButNeverMoreThanTheConcurrency(int $concurrency): void
    {
        $results = [];
        $started = microtime(true);
        $entries = self::withEventServer(static function (string $url) use ($concurrency, &$results): void {
            $results = self::client($url)->getModels(self::refs(9, 'slow-d300'), concurrency: $concurrency);
        });

        self::assertCount(9, $results);
        self::assertCount(9, $entries);
        self::assertSame($concurrency, self::peak($entries), 'requests in flight at the peak');
        if ($concurrency > 1) {
            self::assertLessThan(9 * 0.3, microtime(true) - $started, 'the batch ran in parallel');
        }
    }

    public function testTheDefaultConcurrencyIs4(): void
    {
        $entries = self::withEventServer(static function (string $url): void {
            self::client($url)->getModels(self::refs(10, 'slow-d300'));
        });

        self::assertSame(4, self::peak($entries));
    }

    public function testAThousandRefsWithAWideWindow(): void
    {
        $results = [];
        $entries = self::withEventServer(static function (string $url) use (&$results): void {
            $results = self::client($url)->getModels(self::refs(1000, 'm'), concurrency: 16);
        });

        self::assertCount(1000, $results);
        self::assertSame('m-1000', $results[999]->model?->id);
        self::assertLessThanOrEqual(16, self::peak($entries));
    }

    public function testPerItemFailuresAreResultsNotExceptions(): void
    {
        $url = self::scenario([], [
            '/v1/models/thingiverse/tv-2' => [['status' => 404, 'problem' => 'not_found']],
            '/v1/models/thingiverse/tv-3' => [['status' => 410, 'problem' => 'gone']],
            '/v1/models/thingiverse/tv-4' => [['body' => 'not json']],
        ]);
        $results = self::client($url)->getModels(self::refs(5), concurrency: 2);

        self::assertSame(['tv-1', 'tv-2', 'tv-3', 'tv-4', 'tv-5'], self::ids($results));
        self::assertTrue($results[0]->isOk());
        self::assertInstanceOf(NotFoundException::class, $results[1]->error);
        self::assertInstanceOf(GoneException::class, $results[2]->error);
        self::assertInstanceOf(InvalidResponseException::class, $results[3]->error);
        self::assertFalse($results[3]->isOk());
        self::assertNull($results[3]->model);
        self::assertTrue($results[4]->isOk());
    }

    public function testFailedItemsAreRetriedOnTheirOwn(): void
    {
        $url = self::scenario([], [
            '/v1/models/thingiverse/tv-2' => [['status' => 503, 'problem' => 'unavailable', 'headers' => ['retry-after' => '5']], ['ok' => true]],
            '/v1/models/thingiverse/tv-3' => [['body' => '{"a":', 'truncate' => true], ['ok' => true]],
        ]);
        $results = self::client($url)->getModels(self::refs(4), concurrency: 2);

        self::assertSame([true, true, true, true], array_map(static fn(ModelResult $result): bool => $result->isOk(), $results));
        self::assertCount(6, self::requests($url));
        self::assertContains(5.0, $this->delays);
        self::assertCount(2, $this->delays);
    }

    public function testABatchWaitsForARetryWhenNothingElseRuns(): void
    {
        Hooks::$wait = function (float $delay): float {
            $this->delays[] = $delay;

            return 0.05;
        };
        $url = self::scenario([], ['/v1/models/thingiverse/tv-1' => [['status' => 503, 'problem' => 'unavailable', 'headers' => ['retry-after' => '2']], ['ok' => true]]]);
        $started = microtime(true);
        $results = self::client($url)->getModels(self::refs(1), concurrency: 1);

        self::assertTrue($results[0]->isOk());
        self::assertSame([2.0], $this->delays);
        self::assertGreaterThanOrEqual(0.05, microtime(true) - $started);
    }

    public function testRetriesHonourMaxRetries(): void
    {
        $url = self::scenario([], ['/v1/models/thingiverse/tv-1' => [['status' => 500, 'problem' => 'internal']]]);
        $results = self::client($url, maxRetries: 2)->getModels(self::refs(2));

        self::assertInstanceOf(ApiException::class, $results[0]->error);
        self::assertTrue($results[1]->isOk());
        self::assertCount(4, self::requests($url));
    }

    public function testAnItemThatTimesOutIsATimeoutResult(): void
    {
        $results = [];
        self::withEventServer(static function (string $url) use (&$results): void {
            $refs = [new ModelRef('thingiverse', 'slow-d2000'), new ModelRef('thingiverse', 'fast')];
            $results = self::client($url, timeout: 0.5, maxRetries: 0)->getModels($refs);
        });

        self::assertInstanceOf(ApiTimeoutException::class, $results[0]->error);
        self::assertTrue($results[1]->isOk());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, class-string<ApiException>}>
     */
    public static function fatalErrors(): iterable
    {
        yield 'AuthenticationException' => [['status' => 401, 'problem' => 'invalid_token'], AuthenticationException::class];
        yield 'PermissionDeniedException' => [['status' => 403, 'problem' => 'account_action_required'], PermissionDeniedException::class];
        yield 'QuotaExceededException' => [['status' => 429, 'problem' => 'quota_exceeded', 'headers' => ['retry-after' => '3600']], QuotaExceededException::class];
    }

    /**
     * @param array<string, mixed> $spec
     * @param class-string<ApiException> $class
     */
    #[DataProvider('fatalErrors')]
    public function testShortCircuitsAfterAFatalError(array $spec, string $class): void
    {
        $url = self::scenario([], ['/v1/models/thingiverse/tv-2' => [$spec]]);
        $results = self::client($url)->getModels(self::refs(5), concurrency: 1);

        self::assertTrue($results[0]->isOk());
        self::assertInstanceOf($class, $results[1]->error);
        foreach ([2, 3, 4] as $i) {
            self::assertSame($results[1]->error, $results[$i]->error, "ref {$i} gets the same error");
        }
        self::assertSame(['/v1/models/thingiverse/tv-1', '/v1/models/thingiverse/tv-2'], array_column(self::requests($url), 'route'));
    }

    public function testShortCircuitWithParallelSlotsLetsStartedRefsFinish(): void
    {
        $results = [];
        $refs = [new ModelRef('thingiverse', 'slow-d400'), new ModelRef('thingiverse', 'x401'), ...self::refs(4, 'later')];
        $entries = self::withEventServer(static function (string $url) use ($refs, &$results): void {
            $results = self::client($url)->getModels($refs, concurrency: 2);
        });

        self::assertTrue($results[0]->isOk(), 'slow-d400 was in flight and finishes');
        self::assertInstanceOf(AuthenticationException::class, $results[1]->error);
        foreach ([2, 3, 4, 5] as $i) {
            self::assertSame($results[1]->error, $results[$i]->error);
        }
        self::assertCount(2, $entries);
    }

    public function testAcceptsSearchItemsPairsAndGenerators(): void
    {
        $url = self::scenario();
        $client = self::client($url);
        $items = $client->search('x')->items;
        $refs = (static function () use ($items) {
            yield $items[0];
            yield ['printables', 'pr-1'];
            yield new ModelRef('cults3d', 'c-1');
        })();
        $results = $client->getModels($refs);
        $model = $results[0]->model;
        self::assertNotNull($model);
        $again = $client->getModels([$model]);

        self::assertSame(['tv-1234567', 'pr-1', 'c-1'], self::ids($results));
        self::assertSame('printables', $results[1]->ref->source);
        self::assertSame('tv-1234567', $again[0]->ref->id);
    }

    public function testEmptyInputGivesEmptyOutput(): void
    {
        self::assertSame([], self::client(self::scenario())->getModels([]));
    }

    public function testValidatesConcurrencyAndEveryRefBeforeAnyRequest(): void
    {
        $url = self::scenario();
        $client = self::client($url);

        self::catchError(\InvalidArgumentException::class, static fn() => $client->getModels(self::refs(2), concurrency: 0));
        self::catchError(\InvalidArgumentException::class, static fn() => $client->getModels([new ModelRef('thingiverse', 'tv-1'), new ModelRef('thingiverse', '..')]));
        /** @phpstan-ignore argument.type (invalid on purpose) */
        self::catchError(\InvalidArgumentException::class, static fn() => $client->getModels([['thingiverse']]));
        /** @phpstan-ignore argument.type (invalid on purpose) */
        self::catchError(\InvalidArgumentException::class, static fn() => $client->getModels(['thingiverse/tv-1']));
        self::catchError(\InvalidArgumentException::class, static fn() => $client->getModels(self::refs(1), lang: ''));
        self::assertSame([], self::requests($url));
    }

    public function testTheClientKeepsWorkingAfterABatch(): void
    {
        $url = self::scenario();
        $client = self::client($url);
        $client->getModels(self::refs(3), concurrency: 3);
        $client->me();

        self::assertCount(4, self::requests($url));
    }
}
