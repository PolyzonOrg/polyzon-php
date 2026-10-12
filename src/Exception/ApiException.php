<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * The server answered with an HTTP error. Subclasses tell the cases apart; {@see $errorCode}
 * is the stable machine-readable reason from the `application/problem+json` body.
 *
 * The message reads `401 invalid_token: Токен не найден или отозван. (request id …)`.
 * {@see getCode()} returns the HTTP status.
 */
class ApiException extends PolyzonException
{
    /** HTTP status. */
    public readonly int $status;

    /**
     * Stable error code, e.g. `invalid_token` or `rate_limited`; null when the body is not a
     * problem document (for example an HTML error page of the edge proxy). Named `errorCode`
     * because {@see \Exception::getCode()} already holds the HTTP status.
     */
    public readonly ?string $errorCode;

    /** Short title of the problem, e.g. `Too Many Requests`; null when absent. */
    public readonly ?string $title;

    /** Human-readable explanation (usually in Russian); `HTTP <status>` when absent. */
    public readonly string $detail;

    /** The query parameter at fault (`bad_request`), or null. */
    public readonly ?string $param;

    /** `X-Request-ID` of the response — quote it when contacting support. Null when absent. */
    public readonly ?string $requestId;

    /** Seconds to wait according to the `Retry-After` header, or null when absent. */
    public readonly ?float $retryAfter;

    /**
     * Response headers with lower-case names.
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    /**
     * The parsed JSON object of the body, or null when it is not one (e.g. an HTML page).
     *
     * @var array<mixed>|null
     */
    public readonly ?array $body;

    /**
     * Builds the error from an HTTP answer; the client does this for you.
     *
     * @param int $status HTTP status.
     * @param array<mixed>|null $body Parsed JSON object of the body (problem document), or null.
     * @param array<string, string> $headers Response headers with lower-case names.
     */
    public function __construct(int $status, ?array $body = null, array $headers = [])
    {
        $field = static fn(string $name): ?string => isset($body[$name]) && \is_string($body[$name]) && $body[$name] !== ''
            ? $body[$name]
            : null;
        $this->status = $status;
        $this->errorCode = $field('code');
        $this->title = $field('title');
        $this->detail = $field('detail') ?? 'HTTP ' . $status;
        $this->param = $field('param');
        $requestId = $headers['x-request-id'] ?? '';
        $this->requestId = $requestId !== '' ? $requestId : null;
        $this->retryAfter = self::parseRetryAfter($headers['retry-after'] ?? null);
        $this->headers = $headers;
        $this->body = $body;

        $message = $status . ($this->errorCode !== null ? ' ' . self::clean($this->errorCode) : '') . ': ' . self::clean($this->detail);
        if ($this->requestId !== null) {
            $message .= ' (request id ' . self::clean($this->requestId) . ')';
        }
        parent::__construct($message, $status);
    }

    /**
     * The subclass of {@see ApiException} for an HTTP error answer.
     *
     * @internal
     *
     * @param array<mixed>|null $body
     * @param array<string, string> $headers
     */
    public static function fromResponse(int $status, ?array $body, array $headers): self
    {
        return match (true) {
            $status === 400 => new BadRequestException($status, $body, $headers),
            $status === 401 => new AuthenticationException($status, $body, $headers),
            $status === 403 => new PermissionDeniedException($status, $body, $headers),
            $status === 404 => new NotFoundException($status, $body, $headers),
            $status === 410 => new GoneException($status, $body, $headers),
            $status === 429 => ($body['code'] ?? null) === 'quota_exceeded'
                ? new QuotaExceededException($status, $body, $headers)
                : new RateLimitException($status, $body, $headers),
            $status >= 500 => new InternalServerException($status, $body, $headers),
            default => new self($status, $body, $headers),
        };
    }

    /**
     * Flattens control characters and caps the length of server text placed into messages.
     *
     * @internal
     */
    public static function clean(string $text): string
    {
        $flat = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        if (\strlen($flat) <= 500) {
            return $flat;
        }
        $head = preg_match('/^.{0,500}/su', $flat, $match) === 1 ? $match[0] : substr($flat, 0, 500);

        return $head === $flat ? $flat : $head . '…';
    }

    /** `Retry-After` → seconds: delay-seconds or an HTTP date. Null when absent or unparsable. */
    private static function parseRetryAfter(?string $value): ?float
    {
        $value = trim($value ?? '');
        if (preg_match('/^\d{1,10}(?:\.\d{1,6})?$/D', $value) === 1) {
            return (float) $value;
        }
        // Every HTTP-date format starts with the day name and has a time ("Sun, 06 Nov 1994 08:49:37 GMT").
        $time = preg_match('/^[A-Za-z]{3}\b.*\d\d:\d\d:\d\d/', $value) === 1 ? strtotime($value) : false;

        return $time === false ? null : (float) max(0, $time - time());
    }
}
