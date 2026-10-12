<?php

declare(strict_types=1);

namespace Polyzon\Tests;

use Polyzon\Client;
use Polyzon\Internal\Hooks;

/**
 * Base of the tests: the shared mock API, per-test scenarios, fixtures and instant retries.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** A realistic token: `pzk_` + 43 characters. */
    public const TOKEN = 'pzk_Q7xR2mVbAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    /** Temporary directory of the test run (logs, scenarios, certificates). */
    public static string $dir = '';

    /** Origin of the mock API, e.g. `http://127.0.0.1:41234`. */
    public static string $mockUrl = '';

    /**
     * Retry delays the client asked for in this test (seconds); retries do not really wait.
     *
     * @var list<float>
     */
    protected array $delays = [];

    protected function setUp(): void
    {
        $this->delays = [];
        Hooks::$wait = function (float $delay): float {
            $this->delays[] = $delay;

            return 0.0;
        };
    }

    protected function tearDown(): void
    {
        Hooks::$wait = null;
        Hooks::$random = null;
    }

    /**
     * Examples of the OpenAPI document.
     *
     * @return array<string, mixed>
     */
    protected static function fixture(string $name): array
    {
        /** @var array<string, array<string, mixed>> $fixtures */
        $fixtures = require __DIR__ . '/Fixtures/fixtures.php';

        return $fixtures[$name];
    }

    /**
     * A fresh base URL on the mock API; `$responses` / `$routes` script its answers (see
     * Fixtures/router.php), otherwise it answers like the real API.
     *
     * @param list<array<string, mixed>> $responses
     * @param array<string, list<array<string, mixed>>> $routes
     */
    protected static function scenario(array $responses = [], array $routes = []): string
    {
        $nonce = bin2hex(random_bytes(8));
        if ($responses !== [] || $routes !== []) {
            $scenario = array_filter(['responses' => $responses, 'routes' => $routes]);
            file_put_contents(self::$dir . "/{$nonce}.json", json_encode($scenario, JSON_THROW_ON_ERROR));
        }

        return self::$mockUrl . '/s/' . $nonce;
    }

    /**
     * A client for a mock API base URL: the test token, no proxy.
     *
     * @param (callable(string, string, array<string, mixed>): void)|null $logger
     */
    protected static function client(
        string $baseUrl,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?string $userAgent = null,
        ?callable $logger = null,
    ): Client {
        return new Client(self::TOKEN, $baseUrl, $timeout, $maxRetries, false, null, $userAgent, $logger);
    }

    /**
     * The requests the mock API received for a base URL.
     *
     * @return list<array{uri: string, route: string, query: array<string, string>, headers: array<string, string>, port: int, protocol: string, method: string, time: float}>
     */
    protected static function requests(string $baseUrl): array
    {
        $file = self::$dir . '/' . basename($baseUrl) . '.log';
        $requests = [];
        foreach (is_file($file) ? (array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            /** @var array{uri: string, route: string, query: array<string, string>, headers: array<string, string>, port: int, protocol: string, method: string, time: float} $request */
            $request = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);
            $requests[] = $request;
        }

        return $requests;
    }

    /** The most requests the mock API had in flight at once for a base URL. */
    protected static function maxInFlight(string $baseUrl): int
    {
        $file = self::$dir . '/' . basename($baseUrl) . '.inflight';

        return (int) explode(' ', is_file($file) ? (string) file_get_contents($file) : '0 0')[1];
    }

    /**
     * Runs `$test` against the event-loop server (Fixtures/socket-server.php batch). Unlike
     * PHP's built-in server it answers every connection at once (also on Windows), so slow
     * answers never hold up other requests, and it logs how many requests are in flight.
     *
     * @param callable(string): void $test Gets the base URL.
     *
     * @return list<array<string, mixed>> The server log.
     */
    protected static function withEventServer(callable $test): array
    {
        $server = Server::socket('batch', self::$dir . '/batch-' . bin2hex(random_bytes(6)) . '.log');
        try {
            $test("http://127.0.0.1:{$server->port}");

            return $server->entries();
        } finally {
            $server->stop();
        }
    }

    /**
     * Runs `$test` with environment variables set (null unsets), then restores them.
     *
     * @template T
     *
     * @param array<string, string|null> $vars
     * @param callable(): T $test
     *
     * @return T
     */
    protected static function withEnv(array $vars, callable $test): mixed
    {
        $saved = [];
        foreach ($vars as $name => $value) {
            $saved[$name] = getenv($name);
            putenv($value === null ? $name : "{$name}={$value}");
        }
        try {
            return $test();
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }

    /**
     * Asserts that neither the message nor the SDK's stack frames of `$error` contain `$secret`
     * (the test's own frames hold the secret as test data).
     */
    protected static function assertHidden(string $secret, \Throwable $error): void
    {
        $frames = array_filter($error->getTrace(), static function (array $frame): bool {
            $class = $frame['class'] ?? '';

            return str_starts_with($class, 'Polyzon\\') && !str_starts_with($class, 'Polyzon\\Tests\\');
        });
        self::assertNotSame([], $frames, 'the error comes from the SDK');
        self::assertStringNotContainsString($secret, $error->getMessage());
        self::assertStringNotContainsString($secret, print_r($frames, true));
    }

    /**
     * Calls `$call`, asserts it throws `$class`, and returns the exception.
     *
     * @template E of \Throwable
     *
     * @param class-string<E> $class
     *
     * @return E
     */
    protected static function catchError(string $class, callable $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error, (string) $error);

            return $error;
        }
        self::fail("{$class} was not thrown");
    }
}
