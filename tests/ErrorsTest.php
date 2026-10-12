<?php

declare(strict_types=1);

namespace Polyzon\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Polyzon\Exception\ApiConnectionException;
use Polyzon\Exception\ApiException;
use Polyzon\Exception\ApiTimeoutException;
use Polyzon\Exception\AuthenticationException;
use Polyzon\Exception\BadRequestException;
use Polyzon\Exception\GoneException;
use Polyzon\Exception\InternalServerException;
use Polyzon\Exception\InvalidResponseException;
use Polyzon\Exception\NotFoundException;
use Polyzon\Exception\PermissionDeniedException;
use Polyzon\Exception\PolyzonException;
use Polyzon\Exception\QuotaExceededException;
use Polyzon\Exception\RateLimitException;

final class ErrorsTest extends TestCase
{
    public function testTheClassHierarchyFollowsTheSpec(): void
    {
        $api = [ApiException::class, PolyzonException::class, \RuntimeException::class, \Exception::class];
        $chains = [
            ApiTimeoutException::class => [ApiConnectionException::class, PolyzonException::class, \RuntimeException::class, \Exception::class],
            InvalidResponseException::class => [PolyzonException::class, \RuntimeException::class, \Exception::class],
            BadRequestException::class => $api,
            AuthenticationException::class => $api,
            PermissionDeniedException::class => $api,
            NotFoundException::class => $api,
            GoneException::class => $api,
            InternalServerException::class => $api,
            QuotaExceededException::class => [RateLimitException::class, ...$api],
        ];
        foreach ($chains as $class => $parents) {
            self::assertSame($parents, array_values((array) class_parents($class)), $class);
        }
    }

    /**
     * @return iterable<string, array{int, string|null, class-string<ApiException>}>
     */
    public static function statuses(): iterable
    {
        yield '400 bad_request' => [400, 'bad_request', BadRequestException::class];
        yield '400 page_too_deep' => [400, 'page_too_deep', BadRequestException::class];
        yield '401 unauthorized' => [401, 'unauthorized', AuthenticationException::class];
        yield '401 invalid_token' => [401, 'invalid_token', AuthenticationException::class];
        yield '403 account_action_required' => [403, 'account_action_required', PermissionDeniedException::class];
        yield '404 not_found' => [404, 'not_found', NotFoundException::class];
        yield '410 gone' => [410, 'gone', GoneException::class];
        yield '429 rate_limited' => [429, 'rate_limited', RateLimitException::class];
        yield '429 quota_exceeded' => [429, 'quota_exceeded', QuotaExceededException::class];
        yield '429 without a code' => [429, null, RateLimitException::class];
        yield '500 internal' => [500, 'internal', InternalServerException::class];
        yield '502 without a code' => [502, null, InternalServerException::class];
        yield '503 unavailable' => [503, 'unavailable', InternalServerException::class];
        yield '504' => [504, 'timeout', InternalServerException::class];
        yield '599' => [599, 'whatever', InternalServerException::class];
        yield '405' => [405, 'method_not_allowed', ApiException::class];
        yield '418' => [418, null, ApiException::class];
        yield '304' => [304, null, ApiException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('statuses')]
    public function testStatusMapsToTheErrorClass(int $status, ?string $code, string $class): void
    {
        $spec = $code === null ? ['status' => $status, 'json' => ['detail' => 'x']] : ['status' => $status, 'problem' => $code];
        $error = self::catchError(ApiException::class, static fn() => self::client(self::scenario([$spec]), maxRetries: 0)->me());

        self::assertSame($class, $error::class);
        self::assertSame($status, $error->status);
        self::assertSame($status, $error->getCode());
        self::assertSame($code, $error->errorCode);
    }

    public function testProblemDetailsFieldsHeadersAndMessage(): void
    {
        $url = self::scenario([[
            'status' => 400,
            'problem' => ['code' => 'bad_request', 'title' => 'Bad Request', 'detail' => 'size: от 1 до 50.', 'param' => 'size', 'extra' => 1],
            'headers' => ['x-request-id' => 'req-abc', 'x-ratelimit-remaining' => '162'],
        ]]);

        $error = self::catchError(BadRequestException::class, static fn() => self::client($url)->search('x', size: 500));
        self::assertSame('400 bad_request: size: от 1 до 50. (request id req-abc)', $error->getMessage());
        self::assertSame(['bad_request', 'Bad Request', 'size: от 1 до 50.', 'size', 'req-abc'], [$error->errorCode, $error->title, $error->detail, $error->param, $error->requestId]);
        self::assertNull($error->retryAfter);
        self::assertSame('162', $error->headers['x-ratelimit-remaining']);
        self::assertSame('application/problem+json; charset=utf-8', $error->headers['content-type']);
        self::assertSame(1, $error->body['extra'] ?? null);
        self::assertCount(1, self::requests($url));
    }

    public function testAuthenticationErrorWithRussianDetailNeverShowsTheToken(): void
    {
        $url = self::scenario([['status' => 401, 'problem' => ['code' => 'invalid_token', 'detail' => 'Токен не найден или отозван.'], 'headers' => ['www-authenticate' => 'Bearer']]]);

        $error = self::catchError(AuthenticationException::class, static fn() => self::client($url)->me());
        self::assertSame('401 invalid_token: Токен не найден или отозван. (request id req-err)', $error->getMessage());
        self::assertSame('Bearer', $error->headers['www-authenticate']);
        self::assertStringNotContainsString(self::TOKEN, (string) $error);
        self::assertStringNotContainsString(self::TOKEN, print_r($error->getTrace(), true));
    }

    public function testQuotaExceededExposesRetryAfterAndIsNotRetried(): void
    {
        $url = self::scenario([['status' => 429, 'problem' => 'quota_exceeded', 'headers' => ['retry-after' => '3600']]]);

        $error = self::catchError(QuotaExceededException::class, static fn() => self::client($url, maxRetries: 5)->search('x'));
        self::assertSame(3600.0, $error->retryAfter);
        self::assertCount(1, self::requests($url));
    }

    public function testNonProblemBodiesMapByStatusWithoutTheHtml(): void
    {
        $html = '<html><head><title>502 Bad Gateway</title></head><body>DDoS-Guard</body></html>';
        $url = self::scenario([['status' => 502, 'body' => $html, 'headers' => ['content-type' => 'text/html']]]);

        $error = self::catchError(InternalServerException::class, static fn() => self::client($url, maxRetries: 0)->me());
        self::assertSame('502: HTTP 502 (request id req-123)', $error->getMessage());
        self::assertNull($error->errorCode);
        self::assertSame('HTTP 502', $error->detail);
        self::assertNull($error->body);
        self::assertStringNotContainsString('DDoS', $error->getMessage());
    }

    public function testJsonThatIsNotAProblemDocument(): void
    {
        $url = self::scenario([['status' => 404, 'body' => '{"code": 5, "detail": "", "title": ["x"]}', 'bare' => true]]);

        $error = self::catchError(NotFoundException::class, static fn() => self::client($url)->me());
        self::assertSame('404: HTTP 404', $error->getMessage());
        self::assertSame([null, null, null, null], [$error->errorCode, $error->title, $error->param, $error->requestId]);
        self::assertSame(['code' => 5, 'detail' => '', 'title' => ['x']], $error->body);
    }

    public function testControlCharactersAndLongTextAreFlattenedInTheMessage(): void
    {
        $detail = "line\r\nInjected: header\x00" . str_repeat('я', 600);
        $url = self::scenario([['status' => 400, 'problem' => ['code' => "bad\nrequest", 'detail' => $detail]]]);

        $error = self::catchError(BadRequestException::class, static fn() => self::client($url)->me());
        self::assertStringStartsWith('400 bad request: line Injected: header я', $error->getMessage());
        self::assertStringEndsWith('я… (request id req-err)', $error->getMessage());
        self::assertSame($detail, $error->detail);
        self::assertSame(1, preg_match('//u', $error->getMessage()));
    }

    public function testRedirectsAreNeverFollowed(): void
    {
        $other = self::scenario();
        $url = self::scenario([['status' => 302, 'body' => '', 'headers' => ['location' => $other . '/v1/me']]]);

        $error = self::catchError(ApiException::class, static fn() => self::client($url)->me());
        self::assertSame(ApiException::class, $error::class);
        self::assertSame(302, $error->status);
        self::assertSame($other . '/v1/me', $error->headers['location']);
        self::assertCount(1, self::requests($url));
        self::assertSame([], self::requests($other));
    }

    public function testRetryAfterAsAnHttpDate(): void
    {
        $error = new RateLimitException(429, ['code' => 'rate_limited'], ['retry-after' => gmdate('D, d M Y H:i:s', time() + 30) . ' GMT']);

        self::assertNotNull($error->retryAfter);
        self::assertEqualsWithDelta(30.0, $error->retryAfter, 2.0);
    }

    /**
     * @return iterable<string, array{string, float|null}>
     */
    public static function retryAfterValues(): iterable
    {
        yield 'seconds' => ['5', 5.0];
        yield 'padded' => [' 2 ', 2.0];
        yield 'fraction' => ['1.5', 1.5];
        yield 'date in the past' => ['Sun, 06 Nov 1994 08:49:37 GMT', 0.0];
        yield 'garbage' => ['soon', null];
        yield 'day name only' => ['Mon', null];
        yield 'negative' => ['-5', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('retryAfterValues')]
    public function testRetryAfterParsing(string $header, ?float $expected): void
    {
        self::assertSame($expected, (new InternalServerException(503, null, ['retry-after' => $header]))->retryAfter);
    }

    public function testA2xxBodyOver8MibIsAnInvalidResponse(): void
    {
        $url = self::scenario([['big' => 8 * 1024 * 1024 + 1]]);

        $error = self::catchError(InvalidResponseException::class, static fn() => self::client($url, maxRetries: 3)->me());
        self::assertSame('Response body exceeds 8 MiB (request id req-123)', $error->getMessage());
        self::assertCount(1, self::requests($url));
    }

    public function testABodyOfExactly8MibIsRead(): void
    {
        $body = '{"account":{"id":"' . str_repeat('a', 8 * 1024 * 1024 - 21) . '"}}';
        self::assertSame(8 * 1024 * 1024, \strlen($body));

        $me = self::client(self::scenario([['body' => $body]]))->me();
        self::assertSame(8 * 1024 * 1024 - 21, \strlen($me->account->id));
    }

    public function testAnEndlessBodyIsCutOffAtTheCap(): void
    {
        $started = microtime(true);
        $error = self::catchError(InvalidResponseException::class, static fn() => self::client(self::scenario([['endless' => true]]))->me());

        self::assertStringStartsWith('Response body exceeds 8 MiB', $error->getMessage());
        self::assertLessThan(10.0, microtime(true) - $started);
    }

    public function testAContentLengthOverTheCapFailsBeforeTheBody(): void
    {
        $url = self::scenario([['body' => '{}', 'headers' => ['content-length' => (string) (64 * 1024 * 1024)]]]);

        self::catchError(InvalidResponseException::class, static fn() => self::client($url)->me());
    }

    public function testAnOversizedErrorBodyStillMapsByStatus(): void
    {
        $url = self::scenario([['status' => 500, 'big' => 9 * 1024 * 1024]]);

        $error = self::catchError(InternalServerException::class, static fn() => self::client($url, maxRetries: 0)->me());
        self::assertNull($error->body);
        self::assertSame('500: HTTP 500 (request id req-123)', $error->getMessage());
    }

    public function testAnAttemptThatTimesOut(): void
    {
        $started = microtime(true);
        self::withEventServer(static function (string $url): void {
            $error = self::catchError(ApiTimeoutException::class, static fn() => self::client($url, timeout: 0.5, maxRetries: 0)->getModel('nasa', 'slow-d2500'));
            self::assertSame('Request timed out after 0.5 s.', $error->getMessage());
        });

        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testARefusedConnectionIsAConnectionError(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = parse_url('tcp://' . stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);

        $error = self::catchError(ApiConnectionException::class, static fn() => self::client("http://127.0.0.1:{$port}", maxRetries: 0)->me());
        self::assertSame(ApiConnectionException::class, $error::class);
        self::assertStringStartsWith('Connection error: ', $error->getMessage());
        self::assertStringContainsString('(cURL error 7)', $error->getMessage());
    }

    public function testAConnectionDroppedMidResponseIsAConnectionError(): void
    {
        $url = self::scenario([['body' => '{"account":', 'truncate' => true]]);

        $error = self::catchError(ApiConnectionException::class, static fn() => self::client($url, maxRetries: 0)->me());
        self::assertStringContainsString('(cURL error 18)', $error->getMessage());
    }
}
