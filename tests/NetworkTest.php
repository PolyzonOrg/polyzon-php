<?php

declare(strict_types=1);

namespace Polyzon\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Polyzon\Client;
use Polyzon\Exception\ApiConnectionException;
use Polyzon\Exception\ApiException;

/**
 * Proxies (absolute form, CONNECT, environment), TLS verification and keep-alive.
 */
final class NetworkTest extends TestCase
{
    /** The test certificate covers this name and 127.0.0.1 — not `localhost`. */
    private const HOST = 'api.polyzon.test';

    private const PROXY_ENV = ['https_proxy', 'HTTPS_PROXY', 'http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY'];

    /** @var array{cert: string, key: string}|null */
    private static ?array $certificate = null;

    /** @var list<Server> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        foreach (self::PROXY_ENV as $name) {
            putenv($name);
        }
        unset($_SERVER['HTTP_PROXY']);
        parent::tearDown();
    }

    /**
     * A throwaway self-signed certificate for api.polyzon.test and 127.0.0.1.
     *
     * @return array{cert: string, key: string}
     */
    private static function certificate(): array
    {
        if (self::$certificate !== null) {
            return self::$certificate;
        }
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl is needed to make a test certificate');
        }
        $config = self::$dir . '/openssl.cnf';
        file_put_contents($config, implode("\n", [
            '[req]',
            'distinguished_name = dn',
            '[dn]',
            '[ext]',
            'subjectAltName = DNS:' . self::HOST . ', IP:127.0.0.1',
            'basicConstraints = critical, CA:TRUE',
            'keyUsage = critical, digitalSignature, keyCertSign',
            'extendedKeyUsage = serverAuth',
            '',
        ]));
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048];
        $key = openssl_pkey_new($options);
        $signingKey = $key; // openssl_csr_new() takes the key by reference
        $csr = $signingKey === false ? false : openssl_csr_new(['commonName' => self::HOST], $signingKey, $options);
        $cert = $key === false || \is_bool($csr) ? false : openssl_csr_sign($csr, null, $key, 1, $options, random_int(1, PHP_INT_MAX));
        if ($key === false || $cert === false) {
            self::fail('could not make a certificate: ' . openssl_error_string());
        }
        self::assertTrue(openssl_x509_export_to_file($cert, self::$dir . '/cert.pem'));
        self::assertTrue(openssl_pkey_export_to_file($key, self::$dir . '/key.pem', null, $options));

        return self::$certificate = ['cert' => self::$dir . '/cert.pem', 'key' => self::$dir . '/key.pem'];
    }

    /** A keep-alive HTTPS server answering the `me` example. */
    private function tlsServer(): Server
    {
        $certificate = self::certificate();

        return $this->servers[] = Server::socket('tls', self::$dir . '/tls-' . bin2hex(random_bytes(6)) . '.log', [$certificate['cert'], $certificate['key']]);
    }

    /** A CONNECT proxy; with `$authorization` it demands that Proxy-Authorization. */
    private function connectProxy(string $authorization = '', bool $tls = false): Server
    {
        $args = [$authorization];
        if ($tls) {
            $args = [$authorization, self::certificate()['cert'], self::certificate()['key']];
        }

        return $this->servers[] = Server::socket('proxy', self::$dir . '/proxy-' . bin2hex(random_bytes(6)) . '.log', $args);
    }

    /**
     * Log entries of one kind.
     *
     * @return list<array<string, mixed>>
     */
    private static function events(Server $server, string $event): array
    {
        return array_values(array_filter($server->entries(), static fn(array $entry): bool => $entry['event'] === $event));
    }

    /**
     * Headers of a logged request.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, string>
     */
    private static function headersOf(array $entry): array
    {
        $headers = $entry['headers'] ?? [];
        self::assertIsArray($headers);

        /** @var array<string, string> $headers */
        return $headers;
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) parse_url('tcp://' . stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);

        return $port;
    }

    public function testAnHttpProxyGetsTheAbsoluteFormRequestWithBothAuthorizations(): void
    {
        // The mock API doubles as the proxy: the target port 9 is never contacted.
        $url = self::scenario();
        $target = 'http://127.0.0.1:9' . parse_url($url, PHP_URL_PATH);
        $proxy = str_replace('http://', 'http://us%3Aer:p%40ss%20w@', self::$mockUrl);
        $client = new Client(apiKey: self::TOKEN, baseUrl: $target, proxy: $proxy);
        $client->me();
        $client->search('дракон');

        $requests = self::requests($url);
        self::assertSame($target . '/v1/me', $requests[0]['uri']);
        self::assertSame($target . '/v1/search?q=%D0%B4%D1%80%D0%B0%D0%BA%D0%BE%D0%BD', $requests[1]['uri']);
        self::assertSame('Basic ' . base64_encode('us:er:p@ss w'), $requests[0]['headers']['proxy-authorization']);
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]['headers']['authorization']);
        self::assertSame('127.0.0.1:9', $requests[0]['headers']['host']);
    }

    public function testAProxyUserWithoutPassword(): void
    {
        $url = self::scenario();
        $target = 'http://127.0.0.1:9' . parse_url($url, PHP_URL_PATH);
        (new Client(apiKey: self::TOKEN, baseUrl: $target, proxy: str_replace('http://', 'http://alice@', self::$mockUrl)))->me();

        self::assertSame('Basic ' . base64_encode('alice:'), self::requests($url)[0]['headers']['proxy-authorization']);
    }

    public function testA407FromAnHttpProxyIsAnApiErrorWithoutThePassword(): void
    {
        $url = self::scenario([['status' => 407, 'body' => '', 'headers' => ['proxy-authenticate' => 'Basic']]]);
        $target = 'http://127.0.0.1:9' . parse_url($url, PHP_URL_PATH);
        $client = new Client(apiKey: self::TOKEN, baseUrl: $target, proxy: str_replace('http://', 'http://bob:wrong-S3cret@', self::$mockUrl));

        $error = self::catchError(ApiException::class, static fn() => $client->me());
        self::assertSame(407, $error->status);
        self::assertStringNotContainsString('S3cret', (string) $error);
    }

    public function testLoopbackTargetsIgnoreEnvironmentProxies(): void
    {
        foreach (['https_proxy', 'http_proxy', 'all_proxy', 'HTTP_PROXY'] as $name) {
            putenv("{$name}=http://127.0.0.1:" . self::freePort());
        }
        $url = self::scenario();
        (new Client(apiKey: self::TOKEN, baseUrl: $url))->me();

        self::assertSame(parse_url($url, PHP_URL_PATH) . '/v1/me', self::requests($url)[0]['uri']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProxies(): iterable
    {
        yield 'no scheme' => ['user:s3cret@proxy.example.com:3128'];
        yield 'ftp' => ['ftp://user:s3cret@proxy.example.com'];
        yield 'socks4' => ['socks4://user:s3cret@proxy.example.com'];
        yield 'a path' => ['http://user:s3cret@proxy.example.com:3128/path'];
        yield 'a query' => ['http://user:s3cret@proxy.example.com:3128?x'];
        yield 'a space' => ['http://user:s3cret @proxy.example.com'];
        yield 'no host' => ['http://'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidProxies')]
    public function testInvalidProxyUrlsAreRejectedWithoutEchoingThem(string $proxy): void
    {
        $error = self::catchError(\InvalidArgumentException::class, static fn() => new Client(apiKey: self::TOKEN, proxy: $proxy));

        self::assertHidden('s3cret', $error);
    }

    public function testSupportedProxySchemesAreAccepted(): void
    {
        foreach (['http://p:1', 'HTTPS://p', 'socks5://u:p@p:1080', 'socks5h://[::1]:1080', 'http://p:3128/'] as $proxy) {
            self::assertSame('https://api.polyzon.org', (new Client(apiKey: self::TOKEN, proxy: $proxy))->baseUrl);
        }
    }

    public function testTlsIsVerifiedAgainstTheSystemStoreByDefault(): void
    {
        $server = $this->tlsServer();

        $error = self::catchError(ApiConnectionException::class, static fn() => (new Client(apiKey: self::TOKEN, baseUrl: "https://127.0.0.1:{$server->port}", maxRetries: 0))->me());
        // 60: untrusted certificate; 77: no CA store at all (possible on Windows builds).
        self::assertMatchesRegularExpression('/\(cURL error (60|77)\)/', $error->getMessage());
        self::assertSame([], self::events($server, 'request'));
    }

    public function testACaBundleIsTrustedAndConnectionsAreKeptAlive(): void
    {
        $server = $this->tlsServer();
        $client = new Client(apiKey: self::TOKEN, baseUrl: "https://127.0.0.1:{$server->port}", caBundle: self::certificate()['cert']);
        $me = $client->me();
        $client->me();
        $client->withOptions(maxRetries: 0)->me();

        self::assertSame('req-tls', $me->meta->requestId);
        self::assertCount(1, self::events($server, 'connection'), 'one connection for three requests');
        $requests = self::events($server, 'request');
        self::assertCount(3, $requests);
        self::assertSame('GET /v1/me HTTP/1.1', $requests[0]['line']);
        self::assertSame('Bearer ' . self::TOKEN, self::headersOf($requests[0])['authorization']);
    }

    public function testTheHostNameIsVerified(): void
    {
        $server = $this->tlsServer();
        $client = new Client(apiKey: self::TOKEN, baseUrl: "https://localhost:{$server->port}", caBundle: self::certificate()['cert'], maxRetries: 0);

        $error = self::catchError(ApiConnectionException::class, static fn() => $client->me());
        self::assertMatchesRegularExpression('/\(cURL error (51|60)\)/', $error->getMessage());
        self::assertSame([], self::events($server, 'request'));
    }

    public function testHttpsGoesThroughAConnectTunnelThatNeverSeesTheToken(): void
    {
        $server = $this->tlsServer();
        $proxy = $this->connectProxy('Basic ' . base64_encode('user:p@ss'));
        $client = new Client(
            apiKey: self::TOKEN,
            baseUrl: 'https://' . self::HOST . ":{$server->port}",
            proxy: "http://user:p%40ss@127.0.0.1:{$proxy->port}",
            caBundle: self::certificate()['cert'],
        );
        $client->me();
        $client->limits();

        $connects = self::events($proxy, 'proxy');
        self::assertCount(1, $connects, 'the tunnel is kept alive');
        self::assertSame('CONNECT ' . self::HOST . ":{$server->port} HTTP/1.1", $connects[0]['line']);
        $connectHeaders = self::headersOf($connects[0]);
        self::assertSame('Basic ' . base64_encode('user:p@ss'), $connectHeaders['proxy-authorization']);
        self::assertArrayNotHasKey('authorization', $connectHeaders);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($proxy->entries()));

        $requests = self::events($server, 'request');
        self::assertCount(2, $requests);
        self::assertSame('Bearer ' . self::TOKEN, self::headersOf($requests[0])['authorization']);
        self::assertArrayNotHasKey('proxy-authorization', self::headersOf($requests[0]));
        self::assertSame(self::HOST . ":{$server->port}", self::headersOf($requests[0])['host']);
    }

    public function testA407FromAConnectProxyIsAConnectionErrorWithoutThePassword(): void
    {
        $server = $this->tlsServer();
        $proxy = $this->connectProxy('Basic ' . base64_encode('user:right'));
        $client = new Client(
            apiKey: self::TOKEN,
            baseUrl: 'https://' . self::HOST . ":{$server->port}",
            proxy: "http://user:wrong-S3cret@127.0.0.1:{$proxy->port}",
            caBundle: self::certificate()['cert'],
            maxRetries: 0,
        );

        $error = self::catchError(ApiConnectionException::class, static fn() => $client->me());
        self::assertStringContainsString('407', $error->getMessage());
        self::assertStringNotContainsString('S3cret', (string) $error);
        self::assertSame([], self::events($server, 'request'));
    }

    public function testAnHttpsProxyWrapsTheTunnelInTls(): void
    {
        $server = $this->tlsServer();
        $proxy = $this->connectProxy('', tls: true);
        $client = new Client(
            apiKey: self::TOKEN,
            baseUrl: 'https://' . self::HOST . ":{$server->port}",
            proxy: "https://127.0.0.1:{$proxy->port}",
            caBundle: self::certificate()['cert'],
        );

        self::assertSame('Алексей', $client->me()->account->displayName);
        self::assertCount(1, self::events($proxy, 'proxy'));
    }

    public function testHttpsProxyFromTheEnvironmentIsUsed(): void
    {
        $server = $this->tlsServer();
        $proxy = $this->connectProxy();
        putenv("https_proxy=http://127.0.0.1:{$proxy->port}");
        $client = new Client(apiKey: self::TOKEN, baseUrl: 'https://' . self::HOST . ":{$server->port}", caBundle: self::certificate()['cert']);

        self::assertSame('Алексей', $client->me()->account->displayName);
        self::assertCount(1, self::events($proxy, 'proxy'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string|false|null}>
     */
    public static function bypasses(): iterable
    {
        yield 'NO_PROXY names the host' => [['https_proxy' => 'PROXY', 'no_proxy' => 'example.com,' . self::HOST], null];
        yield 'proxy: false' => [['https_proxy' => 'PROXY', 'all_proxy' => 'PROXY'], false];
        yield 'HTTP_PROXY alone (httpoxy)' => [['HTTP_PROXY' => 'PROXY'], null];
    }

    /**
     * Without a reachable proxy the direct connection to api.polyzon.test (a reserved name)
     * fails: proof that the proxy was bypassed.
     *
     * @param array<string, string> $env
     */
    #[DataProvider('bypasses')]
    public function testTheEnvironmentProxyIsBypassed(array $env, string|false|null $option): void
    {
        $proxy = $this->connectProxy();
        foreach ($env as $name => $value) {
            putenv($name . '=' . str_replace('PROXY', "http://127.0.0.1:{$proxy->port}", $value));
        }
        $_SERVER['HTTP_PROXY'] = "http://127.0.0.1:{$proxy->port}";
        $client = new Client(apiKey: self::TOKEN, baseUrl: 'https://' . self::HOST . ':1', proxy: $option, timeout: 5.0, maxRetries: 0);

        self::catchError(ApiConnectionException::class, static fn() => $client->me());
        self::assertSame([], $proxy->entries());
    }

    public function testAnUnreachableProxyIsAConnectionError(): void
    {
        $client = new Client(apiKey: self::TOKEN, proxy: 'http://user:S3cret@127.0.0.1:' . self::freePort(), maxRetries: 0);

        $error = self::catchError(ApiConnectionException::class, static fn() => $client->me());
        self::assertStringNotContainsString('S3cret', (string) $error);
    }
}
