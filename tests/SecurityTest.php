<?php

declare(strict_types=1);

namespace Polyzon\Tests;

use Polyzon\Client;
use Polyzon\Exception\ApiConnectionException;

/**
 * The token and proxy credentials never show up in any representation of the client, in
 * exception messages or in stack traces.
 */
final class SecurityTest extends TestCase
{
    private const PROXY_PASSWORD = 'pr0xy-S3cret';

    private string $savedIgnoreArgs = '';

    private string $savedParamLength = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Make traces as revealing as possible: full arguments, untruncated.
        $this->savedIgnoreArgs = (string) ini_get('zend.exception_ignore_args');
        $this->savedParamLength = (string) ini_get('zend.exception_string_param_max_len');
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '1000000');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->savedIgnoreArgs);
        ini_set('zend.exception_string_param_max_len', $this->savedParamLength);
        parent::tearDown();
    }

    private static function assertNoSecret(string $text): void
    {
        self::assertStringNotContainsString(self::TOKEN, $text);
        self::assertStringNotContainsString('AAAAAAAAAAAAAAA', $text, 'not even the tail of the token');
        self::assertStringNotContainsString(self::PROXY_PASSWORD, $text);
    }

    private static function capture(callable $print): string
    {
        ob_start();
        $print();

        return (string) ob_get_clean();
    }

    private static function secretClient(): Client
    {
        return new Client(apiKey: self::TOKEN, proxy: 'http://user:' . self::PROXY_PASSWORD . '@127.0.0.1:3128', userAgent: 'bot/1');
    }

    public function testVarDumpAndPrintRShowAMaskedToken(): void
    {
        $client = self::secretClient();
        $dump = self::capture(static function () use ($client): void {
            var_dump($client);
        });
        $printed = print_r($client, true);

        foreach ([$dump, $printed] as $text) {
            self::assertNoSecret($text);
            self::assertStringContainsString('pzk_Q7xR…', $text);
            self::assertStringContainsString('https://api.polyzon.org', $text);
        }
    }

    public function testVarExportJsonArrayCastAndStringCastDoNotLeak(): void
    {
        $client = self::secretClient();

        self::assertNoSecret(var_export($client, true));
        self::assertNoSecret((string) json_encode($client));
        self::assertNoSecret(print_r((array) $client, true));
        self::assertNoSecret(self::capture(static function () use ($client): void {
            var_dump((array) $client);
        }));
        self::assertNoSecret(print_r(get_object_vars($client), true));
        self::assertSame('Polyzon\Client(baseUrl: https://api.polyzon.org, apiKey: pzk_Q7xR…)', (string) $client);
        self::assertSame('{"baseUrl":"https:\/\/api.polyzon.org","timeout":30,"maxRetries":2,"userAgent":"bot\/1"}', json_encode($client));
    }

    public function testShortTokensAreMaskedToo(): void
    {
        self::assertSame('Polyzon\Client(baseUrl: https://api.polyzon.org, apiKey: ab…)', (string) new Client(apiKey: 'abcde'));
        self::assertSame('Polyzon\Client(baseUrl: https://api.polyzon.org, apiKey: …)', (string) new Client(apiKey: 'x'));
    }

    public function testDerivedClientsAreMaskedAndHideTheirOwnToken(): void
    {
        $derived = self::secretClient()->withOptions(apiKey: 'pzk_Derived0123456789');

        self::assertStringContainsString('pzk_Deri…', print_r($derived, true));
        self::assertStringNotContainsString('pzk_Derived0', print_r($derived, true) . var_export($derived, true));
    }

    public function testTheClientCannotBeSerializedClonedOrUnserialized(): void
    {
        $client = self::secretClient();

        $error = self::catchError(\LogicException::class, static fn() => serialize($client));
        self::assertNoSecret((string) $error);
        self::catchError(\LogicException::class, static fn() => unserialize('O:14:"Polyzon\Client":1:{s:7:"baseUrl";s:12:"https://evil";}'));
        self::catchError(\Error::class, static fn() => clone $client);
    }

    public function testConstructorErrorsDoNotLeakTheTokenIntoTraces(): void
    {
        $error = self::catchError(\InvalidArgumentException::class, static fn() => new Client(apiKey: self::TOKEN, baseUrl: 'http://example.com'));

        self::assertNoSecret((string) $error);
        self::assertNoSecret(print_r($error->getTrace(), true));
        self::assertStringContainsString('Object(SensitiveParameterValue)', $error->getTraceAsString());

        $client = new Client(apiKey: self::TOKEN);
        $error = self::catchError(\InvalidArgumentException::class, static fn() => $client->withOptions(apiKey: self::TOKEN . ' ', timeout: 1.0));
        self::assertNoSecret(print_r($error->getTrace(), true));
    }

    public function testRequestErrorsDoNotLeakTheTokenOrProxyPasswordIntoTraces(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = parse_url('tcp://' . stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);
        $client = new Client(apiKey: self::TOKEN, baseUrl: 'http://127.0.0.1:9', proxy: 'http://user:' . self::PROXY_PASSWORD . "@127.0.0.1:{$port}", maxRetries: 0);

        $batch = static function () use ($client): void {
            throw $client->getModels([['nasa', 'n-1']])[0]->error ?? new \LogicException('no error');
        };
        foreach ([static fn() => $client->me(), $batch] as $call) {
            try {
                $call();
                self::fail('no error');
            } catch (ApiConnectionException $error) {
                self::assertStringContainsString('(cURL error 7)', $error->getMessage());
                self::assertNoSecret((string) $error);
                self::assertNoSecret(print_r($error->getTrace(), true));
            }
        }
    }

    public function testTheSdkNeverReadsHttpProxy(): void
    {
        // httpoxy: under CGI, the request header "Proxy:" becomes HTTP_PROXY.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $code = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/getenv\(\s*[\'"]HTTP_PROXY|\$_SERVER\[\s*[\'"]HTTP_PROXY|\$_ENV\[\s*[\'"]HTTP_PROXY/i', $code, $file->getPathname());
            ++$count;
        }
        self::assertGreaterThan(20, $count);
    }
}
