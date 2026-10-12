<?php

declare(strict_types=1);

namespace Polyzon\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Polyzon\Exception\ApiConnectionException;
use Polyzon\Exception\ApiException;
use Polyzon\Exception\ApiTimeoutException;
use Polyzon\Exception\InternalServerException;
use Polyzon\Exception\RateLimitException;
use Polyzon\Internal\Hooks;

final class RetryTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function retriedStatuses(): iterable
    {
        foreach ([500, 502, 503, 504] as $status) {
            yield "HTTP {$status}" => [$status];
        }
    }

    #[DataProvider('retriedStatuses')]
    public function testServerErrorsAreRetried(int $status): void
    {
        $url = self::scenario([['status' => $status, 'problem' => 'internal'], ['ok' => true]]);
        $me = self::client($url)->me();

        self::assertSame('Алексей', $me->account->displayName);
        self::assertCount(2, self::requests($url));
        self::assertCount(1, $this->delays);
    }

    public function testA503WaitsTheRetryAfterTheServerSends(): void
    {
        $url = self::scenario([['status' => 503, 'problem' => 'unavailable', 'headers' => ['retry-after' => '5']], ['ok' => true]]);
        self::client($url)->limits();

        self::assertSame([5.0], $this->delays);
    }

    public function testA429RateLimitedWaitsRetryAfter(): void
    {
        $url = self::scenario([['status' => 429, 'problem' => 'rate_limited', 'headers' => ['retry-after' => '17']], ['ok' => true]]);
        self::client($url)->search('x');

        self::assertSame([17.0], $this->delays);
        self::assertCount(2, self::requests($url));
    }

    public function testARetryAfterOfExactly60SecondsIsHonoured(): void
    {
        $url = self::scenario([['status' => 429, 'problem' => 'rate_limited', 'headers' => ['retry-after' => '60']], ['ok' => true]]);
        self::client($url)->me();

        self::assertSame([60.0], $this->delays);
    }

    public function testARetryAfterOverTheCapIsThrownAtOnce(): void
    {
        $url = self::scenario([['status' => 429, 'problem' => 'rate_limited', 'headers' => ['retry-after' => '61']], ['ok' => true]]);

        $error = self::catchError(RateLimitException::class, static fn() => self::client($url)->me());
        self::assertSame(61.0, $error->retryAfter);
        self::assertSame([], $this->delays);
        self::assertCount(1, self::requests($url));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function finalErrors(): iterable
    {
        yield 'quota_exceeded' => [['status' => 429, 'problem' => 'quota_exceeded', 'headers' => ['retry-after' => '5']]];
        yield '429 without a code' => [['status' => 429, 'json' => ['detail' => 'slow down']]];
        yield '400' => [['status' => 400, 'problem' => 'bad_request']];
        yield '401' => [['status' => 401, 'problem' => 'invalid_token']];
        yield '403' => [['status' => 403, 'problem' => 'account_action_required']];
        yield '404' => [['status' => 404, 'problem' => 'not_found']];
        yield '410' => [['status' => 410, 'problem' => 'gone']];
        yield '501' => [['status' => 501, 'problem' => 'not_implemented']];
    }

    /**
     * @param array<string, mixed> $spec
     */
    #[DataProvider('finalErrors')]
    public function testOtherErrorsAreNotRetried(array $spec): void
    {
        $url = self::scenario([$spec, ['ok' => true]]);

        self::catchError(ApiException::class, static fn() => self::client($url, maxRetries: 5)->me());
        self::assertCount(1, self::requests($url));
        self::assertSame([], $this->delays);
    }

    public function testRetriesStopAfterMaxRetries(): void
    {
        $url = self::scenario([['status' => 503, 'problem' => 'unavailable']]);

        self::catchError(InternalServerException::class, static fn() => self::client($url, maxRetries: 3)->me());
        self::assertCount(4, self::requests($url));
        self::assertCount(3, $this->delays);
    }

    public function testMaxRetriesZeroDisablesRetries(): void
    {
        $url = self::scenario([['status' => 503, 'problem' => 'unavailable']]);

        self::catchError(InternalServerException::class, static fn() => self::client($url, maxRetries: 0)->me());
        self::assertCount(1, self::requests($url));
    }

    public function testBackoffIsExponentialWithJitterAndCappedAt8Seconds(): void
    {
        Hooks::$random = static fn(): float => 1.0;
        $url = self::scenario([['status' => 500, 'problem' => 'internal']]);

        self::catchError(InternalServerException::class, static fn() => self::client($url, maxRetries: 6)->me());
        self::assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0], $this->delays);
    }

    public function testTheJitterMultiplierIsBetweenHalfAndOne(): void
    {
        Hooks::$random = static fn(): float => 0.0;
        $url = self::scenario([['status' => 500, 'problem' => 'internal'], ['status' => 500, 'problem' => 'internal'], ['ok' => true]]);
        self::client($url)->me();
        self::assertSame([0.25, 0.5], $this->delays);
    }

    public function testTheDefaultJitterStaysInRange(): void
    {
        $url = self::scenario([['status' => 500, 'problem' => 'internal'], ['ok' => true]]);
        self::client($url)->me();

        self::assertCount(1, $this->delays);
        self::assertGreaterThanOrEqual(0.25, $this->delays[0]);
        self::assertLessThanOrEqual(0.5, $this->delays[0]);
    }

    public function testConnectionResetsAreRetried(): void
    {
        $url = self::scenario([['body' => '{"account":', 'truncate' => true], ['ok' => true]]);
        $me = self::client($url)->me();

        self::assertSame('Алексей', $me->account->displayName);
        self::assertCount(2, self::requests($url));
    }

    public function testATimedOutAttemptIsRetried(): void
    {
        $entries = self::withEventServer(static function (string $url): void {
            self::assertSame('once-d2000', self::client($url, timeout: 0.5)->getModel('nasa', 'once-d2000')->id);
        });

        self::assertCount(2, $entries);
    }

    public function testRetriesReallyWaitWithoutTheTestHook(): void
    {
        Hooks::$wait = null;
        $url = self::scenario([['status' => 503, 'problem' => 'unavailable', 'headers' => ['retry-after' => '0.2']], ['ok' => true]]);
        $started = microtime(true);
        self::client($url)->me();

        self::assertGreaterThanOrEqual(0.2, microtime(true) - $started);
    }

    public function testTheLoggerGetsAttemptsAndRetriesButNeverTheToken(): void
    {
        $lines = [];
        $logger = static function (string $level, string $message, array $context) use (&$lines): void {
            $lines[] = [$level, $message, $context];
        };
        $url = self::scenario([['status' => 503, 'problem' => 'unavailable', 'headers' => ['retry-after' => '1']], ['ok' => true]]);
        self::client($url, logger: $logger)->search('ваза', size: 5);

        self::assertCount(3, $lines);
        [$first, $retry, $last] = $lines;
        self::assertSame('debug', $first[0]);
        self::assertMatchesRegularExpression('~^polyzon: GET /v1/search\?q=%D0%B2%D0%B0%D0%B7%D0%B0&size=5 -> 503 in \d+ ms \(attempt 1\)$~', $first[1]);
        self::assertSame(['method' => 'GET', 'path' => '/v1/search?q=%D0%B2%D0%B0%D0%B7%D0%B0&size=5', 'status' => 503, 'attempt' => 1], array_diff_key($first[2], ['durationMs' => 0]));
        self::assertSame('warning', $retry[0]);
        self::assertStringContainsString('failed (503 unavailable: Ошибка 503 (request id req-err)); retry 1 of 2 in 1.00 s', $retry[1]);
        self::assertSame(1.0, $retry[2]['delay']);
        self::assertSame(InternalServerException::class, $retry[2]['error']);
        self::assertSame('debug', $last[0]);
        self::assertStringContainsString('-> 200 in', $last[1]);
        $dump = print_r($lines, true);
        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString('Bearer', $dump);
        self::assertStringNotContainsString('Authorization', $dump);
    }

    public function testConnectionFailuresAreRetriedAndThenThrown(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = parse_url('tcp://' . stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);

        self::catchError(ApiConnectionException::class, static fn() => self::client("http://127.0.0.1:{$port}", maxRetries: 2)->me());
        self::assertCount(2, $this->delays);
    }

    public function testTimeoutsAreThrownAfterTheRetries(): void
    {
        $entries = self::withEventServer(static function (string $url): void {
            self::catchError(ApiTimeoutException::class, static fn() => self::client($url, timeout: 0.4, maxRetries: 1)->getModel('nasa', 'slow-d2000'));
        });

        self::assertCount(2, $entries);
    }
}
