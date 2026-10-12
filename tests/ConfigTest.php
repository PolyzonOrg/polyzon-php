<?php

declare(strict_types=1);

namespace Polyzon\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Polyzon\Client;
use Polyzon\Lang;
use Polyzon\License;
use Polyzon\Sort;
use Polyzon\Source;

final class ConfigTest extends TestCase
{
    public function testConstantsListTheKnownValues(): void
    {
        self::assertSame('1.0.0', Client::VERSION);
        self::assertCount(22, Source::ALL);
        self::assertContains('3dtoday', Source::ALL);
        self::assertSame('3dexport', Source::THREE_D_EXPORT);
        self::assertCount(15, License::ALL);
        self::assertSame(['relevant', 'popular', 'newest', 'likes', 'makes', 'downloads'], Sort::ALL);
        self::assertSame(['ru', 'en'], Lang::ALL);
    }

    public function testAMissingApiKeyPointsToTheEnvironmentVariable(): void
    {
        self::withEnv(['POLYZON_API_KEY' => null], static function (): void {
            $error = self::catchError(\InvalidArgumentException::class, static fn() => new Client());
            self::assertStringContainsString('POLYZON_API_KEY', $error->getMessage());
            self::catchError(\InvalidArgumentException::class, static fn() => new Client(apiKey: ''));
        });
    }

    public function testTheApiKeyAndBaseUrlAreReadFromTheEnvironment(): void
    {
        $url = self::scenario();
        self::withEnv(['POLYZON_API_KEY' => ' pzk_fromEnv ', 'POLYZON_BASE_URL' => $url . '/'], static function () use ($url): void {
            $client = new Client(proxy: false);
            self::assertSame($url, $client->baseUrl);
            $client->me();
            self::assertSame('Bearer pzk_fromEnv', self::requests($url)[0]['headers']['authorization']);

            // Explicit options win over the environment.
            $other = self::scenario();
            self::client($other)->me();
            self::assertSame('Bearer ' . self::TOKEN, self::requests($other)[0]['headers']['authorization']);
        });
    }

    public function testTheDefaultBaseUrl(): void
    {
        self::withEnv(['POLYZON_BASE_URL' => null], static function (): void {
            self::assertSame('https://api.polyzon.org', (new Client(apiKey: self::TOKEN))->baseUrl);
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidApiKeys(): iterable
    {
        yield 'a space' => ['pzk_abc def'];
        yield 'a line break' => ["pzk_abc\r\nX-Injected: 1"];
        yield 'a trailing newline' => ["pzk_abcdef\n"];
        yield 'a NUL byte' => ["pzk_abc\0def"];
        yield 'a tab' => ["pzk_abc\tdef"];
        yield 'non-ASCII' => ['pzk_ключ_secret'];
        yield 'too long' => ['pzk_' . str_repeat('s', 509)];
    }

    #[DataProvider('invalidApiKeys')]
    public function testInvalidApiKeysAreRejectedWithoutEchoingThem(string $apiKey): void
    {
        $error = self::catchError(\InvalidArgumentException::class, static fn() => new Client(apiKey: $apiKey));

        foreach (['abc', 'secret', 'sss', 'ключ'] as $part) {
            self::assertHidden($part, $error);
        }
    }

    public function testThePzkFormatIsNotEnforced(): void
    {
        self::assertSame('https://api.polyzon.org', (new Client(apiKey: 'x'))->baseUrl);
        self::assertSame('https://api.polyzon.org', (new Client(apiKey: str_repeat('~', 512)))->baseUrl);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validBaseUrls(): iterable
    {
        yield 'trailing slashes stripped' => ['https://api.polyzon.org//', 'https://api.polyzon.org'];
        yield 'path prefix kept' => ['https://gateway.example.com/polyzon/', 'https://gateway.example.com/polyzon'];
        yield 'port kept, case folded' => ['HTTPS://API.Polyzon.org:8443', 'https://api.polyzon.org:8443'];
        yield 'http localhost' => ['http://localhost:8080', 'http://localhost:8080'];
        yield 'http 127.0.0.1' => ['http://127.0.0.1', 'http://127.0.0.1'];
        yield 'http [::1]' => ['http://[::1]:9000/', 'http://[::1]:9000'];
    }

    #[DataProvider('validBaseUrls')]
    public function testValidBaseUrls(string $baseUrl, string $expected): void
    {
        self::assertSame($expected, (new Client(apiKey: self::TOKEN, baseUrl: $baseUrl))->baseUrl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBaseUrls(): iterable
    {
        yield 'plain http' => ['http://api.polyzon.org'];
        yield 'http to a LAN host' => ['http://192.168.1.10'];
        yield 'http to a lookalike' => ['http://localhost.evil.com'];
        yield 'credentials' => ['https://user:secret@api.polyzon.org'];
        yield 'a query' => ['https://api.polyzon.org/?x=1'];
        yield 'a fragment' => ['https://api.polyzon.org/#x'];
        yield 'ftp' => ['ftp://api.polyzon.org'];
        yield 'no scheme' => ['api.polyzon.org'];
        yield 'relative' => ['/v1'];
        yield 'empty' => [''];
        yield 'spaces' => ['https://api.polyzon.org /x'];
        yield 'a backslash' => ['https://evil.com\\@api.polyzon.org'];
        yield 'a bad port' => ['https://api.polyzon.org:99999'];
        yield 'file' => ['file:///etc/passwd'];
    }

    #[DataProvider('invalidBaseUrls')]
    public function testInvalidBaseUrlsAreRejected(string $baseUrl): void
    {
        $error = self::catchError(\InvalidArgumentException::class, static fn() => new Client(apiKey: self::TOKEN, baseUrl: $baseUrl));

        self::assertStringNotContainsString('secret', $error->getMessage());
    }

    public function testNumericOptions(): void
    {
        $client = new Client(apiKey: self::TOKEN);
        self::assertSame([30.0, 2], [$client->timeout, $client->maxRetries]);
        self::assertSame(0, (new Client(apiKey: self::TOKEN, maxRetries: 0))->maxRetries);
        self::assertSame(5.0, (new Client(apiKey: self::TOKEN, timeout: 5))->timeout);

        foreach ([0.0, -1.0, NAN, INF, 86401.0] as $timeout) {
            self::catchError(\InvalidArgumentException::class, static fn() => new Client(apiKey: self::TOKEN, timeout: $timeout));
        }
        self::catchError(\InvalidArgumentException::class, static fn() => new Client(apiKey: self::TOKEN, maxRetries: -1));
    }

    public function testInvalidUserAgentSuffixesAndCaBundles(): void
    {
        foreach (['', ' my-bot', 'my-bot ', "my-bot\r\nX: 1", 'бот', str_repeat('a', 257)] as $userAgent) {
            self::catchError(\InvalidArgumentException::class, static fn() => new Client(apiKey: self::TOKEN, userAgent: $userAgent));
        }
        foreach (['', self::$dir . '/missing.pem', self::$dir] as $caBundle) {
            self::catchError(\InvalidArgumentException::class, static fn() => new Client(apiKey: self::TOKEN, caBundle: $caBundle));
        }
    }

    public function testSendsExactlyAuthorizationAcceptAndUserAgentOverHttp11(): void
    {
        $url = self::scenario();
        self::client($url)->me();

        $request = self::requests($url)[0];
        self::assertSame('GET', $request['method']);
        self::assertSame('HTTP/1.1', $request['protocol']);
        self::assertSame(['host', 'authorization', 'accept', 'user-agent'], array_keys($request['headers']));
        self::assertSame('Bearer ' . self::TOKEN, $request['headers']['authorization']);
        self::assertSame('application/json', $request['headers']['accept']);
        self::assertMatchesRegularExpression('~^polyzon-php/1\.0\.0 \(php \d+\.\d+\.\d+\S*; [a-z]+/\S+\)$~', $request['headers']['user-agent']);
    }

    public function testTheUserAgentSuffixIsAppended(): void
    {
        $url = self::scenario();
        self::client($url, userAgent: 'my-bot/2.1 (+https://example.com)')->me();

        self::assertStringEndsWith(') my-bot/2.1 (+https://example.com)', self::requests($url)[0]['headers']['user-agent']);
    }

    public function testWithOptionsDerivesAClientAndKeepsTheOriginal(): void
    {
        $url = self::scenario([['status' => 503, 'problem' => 'unavailable']]);
        $lines = [];
        $client = self::client($url, timeout: 7.0, userAgent: 'base/1');
        $derived = $client->withOptions(apiKey: 'pzk_other', maxRetries: 0, logger: static function (string $level, string $message) use (&$lines): void {
            $lines[] = $message;
        });

        self::assertNotSame($client, $derived);
        self::assertSame([$url, 7.0, 0, 'base/1'], [$derived->baseUrl, $derived->timeout, $derived->maxRetries, $derived->userAgent]);
        self::assertSame(2, $client->maxRetries);
        self::catchError(\Polyzon\Exception\InternalServerException::class, static fn() => $derived->me());
        self::assertSame(['Bearer pzk_other'], array_map(static fn(array $request): string => $request['headers']['authorization'], self::requests($url)));
        self::assertCount(1, $lines);

        self::catchError(\InvalidArgumentException::class, static fn() => $client->withOptions(timeout: -1.0));
        self::catchError(\InvalidArgumentException::class, static fn() => $client->withOptions(apiKey: "a\nb"));
        $same = $client->withOptions();
        self::assertSame((string) $client, (string) $same);
    }

    public function testDerivedClientsShareTheProxySettings(): void
    {
        // The mock API doubles as a forward proxy: it sees the absolute-form request.
        $url = self::scenario();
        $target = 'http://127.0.0.1:9' . parse_url($url, PHP_URL_PATH);
        $client = new Client(apiKey: self::TOKEN, baseUrl: $target, proxy: self::$mockUrl, maxRetries: 0);
        $client->withOptions(apiKey: 'pzk_derived')->me();

        $requests = self::requests($url);
        self::assertSame($target . '/v1/me', $requests[0]['uri']);
        self::assertSame('Bearer pzk_derived', $requests[0]['headers']['authorization']);
    }
}
