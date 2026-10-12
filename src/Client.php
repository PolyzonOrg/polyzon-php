<?php

declare(strict_types=1);

namespace Polyzon;

use Polyzon\Exception\ApiConnectionException;
use Polyzon\Exception\ApiException;
use Polyzon\Exception\AuthenticationException;
use Polyzon\Exception\InvalidResponseException;
use Polyzon\Exception\PermissionDeniedException;
use Polyzon\Exception\PolyzonException;
use Polyzon\Exception\QuotaExceededException;
use Polyzon\Internal\Hooks;
use Polyzon\Internal\Parse;
use Polyzon\Internal\Response;
use Polyzon\Internal\Transport;

/**
 * Client of the Polyzon public API (https://api.polyzon.org).
 *
 * Immutable: the options are fixed at construction, and {@see withOptions()} derives a client
 * that shares the connections. Reuse one instance for sequential requests and batches: it
 * keeps connections alive. Do not share an instance between threads (ext-parallel); create
 * one client per thread instead.
 *
 * ```php
 * $client = new \Polyzon\Client(); // reads POLYZON_API_KEY
 * $page = $client->search('дракон', size: 10);
 * ```
 */
final class Client
{
    /** Version of this SDK; it is also sent in the `User-Agent` header. */
    public const VERSION = '1.0.0';

    /** Default API origin. */
    public const DEFAULT_BASE_URL = 'https://api.polyzon.org';

    /** Longest `Retry-After` the client waits for; a longer one is thrown at once. */
    private const MAX_RETRY_AFTER = 60.0;

    /** The server serves at most this many pages. */
    private const MAX_PAGE = 100;

    private const MAX_TIMEOUT = 86400.0;

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * API tokens, kept out of the object's properties so that var_export(), print_r(),
     * array casts and serializers never see them.
     *
     * @var \WeakMap<self, string>|null
     */
    private static ?\WeakMap $apiKeys = null;

    /**
     * API origin requests go to, without a trailing slash.
     *
     * @var non-empty-string
     */
    public readonly string $baseUrl;

    /** Time limit of one attempt (connect + answer), seconds. */
    public readonly float $timeout;

    /** Retries of a failed request. */
    public readonly int $maxRetries;

    /** Text appended to the default `User-Agent`, or null. */
    public readonly ?string $userAgent;

    /** The connections; shared with derived clients (assigned once, right after construction). */
    private Transport $transport;

    /** @var (\Closure(string, string, array<string, mixed>): void)|null */
    private readonly ?\Closure $logger;

    private readonly string $userAgentHeader;

    /**
     * @param string|null $apiKey API token (`pzk_…`). Default: the `POLYZON_API_KEY` environment variable.
     * @param string|null $baseUrl API origin. Default: `POLYZON_BASE_URL`, else `https://api.polyzon.org`.
     *                             Must be `https://`; `http://` only for `localhost`, `127.0.0.1` and `[::1]`.
     * @param float $timeout Time limit of one attempt (connect + answer), seconds. Connecting takes at most 10 s of it.
     * @param int $maxRetries Retries of a failed request (connection errors, timeouts, 5xx, `rate_limited`); 0 disables.
     * @param string|false|null $proxy Proxy URL `http://`, `https://`, `socks5://` or `socks5h://` with optional
     *                                 `user:pass@`. Null — the `https_proxy` / `HTTPS_PROXY` / `all_proxy` /
     *                                 `no_proxy` environment (as libcurl reads it); false — no proxy at all.
     * @param string|null $caBundle Path of a PEM CA bundle to trust instead of the default one (e.g. a
     *                              TLS-inspecting corporate proxy). TLS is always verified.
     * @param string|null $userAgent Appended to the default `User-Agent`, e.g. `my-bot/2.1`.
     * @param (callable(string, string, array<string, mixed>): void)|null $logger Gets `(level, message, context)`:
     *                                                                         `debug` per attempt, `warning` per retry.
     *                                                                         Never gets headers or the token.
     *
     * @throws \InvalidArgumentException On an invalid option (the token is never echoed).
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        int $maxRetries = 2,
        #[\SensitiveParameter]
        string|false|null $proxy = null,
        ?string $caBundle = null,
        ?string $userAgent = null,
        ?callable $logger = null,
    ) {
        $apiKey ??= self::env('POLYZON_API_KEY') ?? '';
        if ($apiKey === '') {
            throw new \InvalidArgumentException(
                'Missing API key: pass apiKey or set POLYZON_API_KEY. Get a token at https://polyzon.org/account/',
            );
        }
        if (preg_match('/^[\x21-\x7E]{1,512}$/D', $apiKey) !== 1) {
            // Never echo the value: it is a secret.
            throw new \InvalidArgumentException('apiKey must be 1..512 visible ASCII characters (no spaces or line breaks)');
        }
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > self::MAX_TIMEOUT) {
            throw new \InvalidArgumentException('timeout must be a number of seconds above 0 and at most 86400');
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must not be negative');
        }
        if ($userAgent !== null && preg_match('/^[\x21-\x7E](?:[\x20-\x7E]{0,254}[\x21-\x7E])?$/D', $userAgent) !== 1) {
            throw new \InvalidArgumentException('userAgent must be 1..256 printable ASCII characters without outer spaces');
        }
        self::$apiKeys ??= new \WeakMap();
        self::$apiKeys[$this] = $apiKey;
        $this->baseUrl = self::checkBaseUrl($baseUrl ?? self::env('POLYZON_BASE_URL') ?? self::DEFAULT_BASE_URL);
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->userAgent = $userAgent;
        $this->logger = $logger === null ? null : $logger(...);
        $this->transport = Transport::create($this->baseUrl, $proxy, $caBundle);
        $arch = \function_exists('php_uname') ? strtolower(php_uname('m')) : '';
        $this->userAgentHeader = \sprintf(
            'polyzon-php/%s (php %s; %s/%s)%s',
            self::VERSION,
            PHP_VERSION,
            strtolower(PHP_OS_FAMILY),
            $arch !== '' ? $arch : 'unknown',
            $userAgent !== null ? ' ' . $userAgent : '',
        );
    }

    /**
     * A client with some options changed that shares this client's connections (and so its
     * base URL, proxy and CA bundle). Unset arguments keep their current values.
     *
     * @param (callable(string, string, array<string, mixed>): void)|null $logger
     *
     * @throws \InvalidArgumentException On an invalid option.
     */
    public function withOptions(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
        ?string $userAgent = null,
        ?callable $logger = null,
    ): self {
        $client = new self(
            $apiKey ?? $this->apiKey(),
            $this->baseUrl,
            $timeout ?? $this->timeout,
            $maxRetries ?? $this->maxRetries,
            false,
            null,
            $userAgent ?? $this->userAgent,
            $logger ?? $this->logger,
        );
        $client->transport = $this->transport;

        return $client;
    }

    /**
     * Searches the catalog: one page of results. Costs one request of the daily quota (even
     * when the server rejects the parameters).
     *
     * @param string $q Search text in Russian or English, up to 200 characters.
     * @param int|null $page Page number from 1 (server limit: 100). Default 1.
     * @param int|null $size Models per page, 1..50. Default 20. `page × size` must not exceed 2400.
     * @param string|null $sort Sort order, see {@see Sort}. Default `relevant`.
     * @param bool|null $free True — only free models. False or null — no price filter.
     * @param list<string>|null $sources Only these sources, e.g. `['thingiverse', 'printables']` (see {@see Source}).
     * @param list<string>|null $formats Only models with these file formats, e.g. `['stl', '3mf']`.
     * @param list<string>|null $licenses Only models under these licenses, e.g. `['cc0', 'cc-by']` (see {@see License}).
     * @param string|null $lang Language of titles and tags (machine-translated where needed), see {@see Lang}.
     *
     * @throws \InvalidArgumentException On an invalid argument, before any request.
     * @throws PolyzonException When the request fails.
     */
    public function search(
        string $q,
        ?int $page = null,
        ?int $size = null,
        ?string $sort = null,
        ?bool $free = null,
        ?array $sources = null,
        ?array $formats = null,
        ?array $licenses = null,
        ?string $lang = null,
    ): SearchPage {
        $query = self::searchQuery($q, $page, $size, $sort, $free, $sources, $formats, $licenses, $lang);

        return Parse::searchPage(...$this->get(self::target('/v1/search', $query)));
    }

    /**
     * Walks search results page by page, starting at `$page` (default 1), until `hasMore` is
     * false, a page is empty, or page 100 (the server's limit). Pages are fetched lazily as you
     * iterate; each page costs one request of the daily quota, so use `size: 50` and stop as
     * soon as you have enough. Takes the arguments of {@see search()}.
     *
     * @param list<string>|null $sources
     * @param list<string>|null $formats
     * @param list<string>|null $licenses
     *
     * @return \Generator<int, SearchItem, mixed, void>
     *
     * @throws \InvalidArgumentException At once on an invalid argument; request errors are thrown while iterating.
     */
    public function searchAll(
        string $q,
        ?int $page = null,
        ?int $size = null,
        ?string $sort = null,
        ?bool $free = null,
        ?array $sources = null,
        ?array $formats = null,
        ?array $licenses = null,
        ?string $lang = null,
    ): \Generator {
        $query = self::searchQuery($q, null, $size, $sort, $free, $sources, $formats, $licenses, $lang);

        return $this->walk($query, $page === null ? 1 : self::positive($page, 'page'));
    }

    /**
     * One model card. Costs one request of the daily quota.
     *
     * @param string $source Source id, e.g. `thingiverse` (as in search items).
     * @param string $id Model id, e.g. `tv-1234567` (as in search items).
     * @param string|null $lang Language of the title, description and tags, see {@see Lang}.
     *
     * @throws \InvalidArgumentException On an invalid source or id, before any request.
     * @throws Exception\NotFoundException When there is no such model.
     * @throws Exception\GoneException When the model was removed at the right holder's request: delete your copy.
     * @throws PolyzonException When the request fails otherwise.
     */
    public function getModel(string $source, string $id, ?string $lang = null): Model
    {
        return Parse::model(...$this->get(self::target(self::modelPath($source, $id), self::langQuery($lang))));
    }

    /**
     * Fetches many models in parallel (curl_multi) with at most `$concurrency` requests in
     * flight. Returns one result per reference in input order, each holding the model or the
     * error: a failed model never throws. Failed requests are retried like any other. After an
     * {@see AuthenticationException}, {@see PermissionDeniedException} or
     * {@see QuotaExceededException} the references not yet started get that same error without
     * a request. Each model costs one request of the daily quota.
     *
     * @param iterable<ModelRef|SearchItem|Model|array{string, string}> $refs Models to fetch: references,
     *                                                                         search items or `[source, id]` pairs.
     * @param int $concurrency Requests in flight at most, at least 1.
     * @param string|null $lang Language of titles, descriptions and tags, see {@see Lang}.
     *
     * @return list<ModelResult>
     *
     * @throws \InvalidArgumentException On an invalid reference or concurrency, before any request.
     */
    public function getModels(iterable $refs, int $concurrency = 4, ?string $lang = null): array
    {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('concurrency must be at least 1');
        }
        $query = self::langQuery($lang);
        $list = [];
        $targets = [];
        foreach ($refs as $ref) {
            $list[] = $ref = self::ref($ref);
            $targets[] = self::target(self::modelPath($ref->source, $ref->id), $query);
        }

        return $list === [] ? [] : $this->batch($list, $targets, $concurrency);
    }

    /**
     * The account and the token of this client. Does not cost daily quota.
     *
     * @throws PolyzonException When the request fails.
     */
    public function me(): Me
    {
        return Parse::me(...$this->get('/v1/me'));
    }

    /**
     * Remaining quota: the per-minute window and the UTC day. Does not cost daily quota.
     *
     * @throws PolyzonException When the request fails.
     */
    public function limits(): Limits
    {
        return Parse::limits(...$this->get('/v1/limits'));
    }

    /**
     * Masked view for var_dump() and print_r(): the token shows as `pzk_Q7xR…`.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'apiKey' => self::mask($this->apiKey()),
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
            'userAgent' => $this->userAgent,
        ];
    }

    /** Masked one-line description, e.g. for logs. */
    public function __toString(): string
    {
        return \sprintf('Polyzon\Client(baseUrl: %s, apiKey: %s)', $this->baseUrl, self::mask($this->apiKey()));
    }

    /**
     * A client holds a secret token and connections: it cannot be serialized.
     *
     * @return array<never>
     *
     * @throws \LogicException Always.
     */
    public function __serialize(): array
    {
        throw new \LogicException('Polyzon\Client cannot be serialized: it holds a secret API token');
    }

    /**
     * Refuses to restore a client from serialized data.
     *
     * @param array<mixed> $data
     *
     * @throws \LogicException Always.
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Polyzon\Client cannot be unserialized');
    }

    /** Clients are immutable: derive one with {@see withOptions()} instead of cloning. */
    private function __clone() {}

    /**
     * @param array<string, string> $query
     *
     * @return \Generator<int, SearchItem, mixed, void>
     */
    private function walk(array $query, int $page): \Generator
    {
        for (;; ++$page) {
            $result = Parse::searchPage(...$this->get(self::target('/v1/search', ['q' => $query['q'], 'page' => (string) $page] + $query)));
            foreach ($result->items as $item) {
                yield $item;
            }
            if (!$result->hasMore || $result->items === [] || $page >= self::MAX_PAGE) {
                return;
            }
        }
    }

    /**
     * GET with retries.
     *
     * @return array{array<mixed>, ResponseMeta} The JSON object body and its metadata.
     */
    private function get(string $target): array
    {
        for ($attempt = 0;; ++$attempt) {
            $started = self::now();
            try {
                $response = $this->transport->send($this->baseUrl . $target, $this->headers(), $this->timeout);
                $outcome = $this->settle($response, $target, $attempt, $started);
                if (\is_array($outcome)) {
                    return $outcome;
                }
            } catch (ApiConnectionException $error) {
                $outcome = $error;
            }
            $delay = $this->retryDelay($outcome, $attempt);
            if ($delay === null) {
                throw $outcome;
            }
            $this->logRetry($target, $outcome, $attempt, $delay);
            $wait = self::wait($delay);
            if ($wait > 0) {
                usleep((int) round($wait * 1_000_000));
            }
        }
    }

    /**
     * Fetches models over curl_multi: a sliding window of `$concurrency` slots; a slot stays
     * taken while its request waits for a retry.
     *
     * @param list<ModelRef> $refs
     * @param list<string> $targets
     *
     * @return list<ModelResult>
     */
    private function batch(array $refs, array $targets, int $concurrency): array
    {
        $multi = curl_multi_init();
        $headers = $this->headers();
        $count = \count($refs);
        $next = 0;
        $fatal = null;
        /** @var array<int, ModelResult> $results */
        $results = [];
        /** @var array<int, array{int, int, \CurlHandle, Response, int}> $running handle id => [index, attempt, handle, response, start] */
        $running = [];
        /** @var array<int, array{int, int}> $waiting index => [attempt, wake-up time in hrtime nanoseconds] */
        $waiting = [];
        /** @var list<\CurlHandle> $idle */
        $idle = [];

        while ($next < $count || $running !== [] || $waiting !== []) {
            $now = self::now();
            foreach ($waiting as $index => [$attempt, $wakeAt]) {
                if ($wakeAt <= $now) {
                    unset($waiting[$index]);
                    $running += $this->launch($multi, $idle, $index, $attempt, $targets[$index], $headers);
                }
            }
            while ($next < $count && \count($running) + \count($waiting) < $concurrency) {
                $index = $next++;
                if ($fatal !== null) {
                    $results[$index] = new ModelResult($refs[$index], null, $fatal);
                } else {
                    $running += $this->launch($multi, $idle, $index, 0, $targets[$index], $headers);
                }
            }
            if ($running === []) {
                if ($waiting !== []) {
                    usleep(max(0, intdiv(min(array_column($waiting, 1)) - self::now(), 1000)));
                }
                continue;
            }

            do {
                $status = curl_multi_exec($multi, $active);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            $finished = false;
            while (($info = curl_multi_info_read($multi)) !== false) {
                $finished = true;
                /** @var \CurlHandle $handle */
                $handle = $info['handle'];
                /** @var int $errno */
                $errno = $info['result'];
                [$index, $attempt, , $response, $started] = $running[spl_object_id($handle)];
                unset($running[spl_object_id($handle)]);
                curl_multi_remove_handle($multi, $handle);
                $idle[] = $handle;
                try {
                    $outcome = $this->settle(
                        $this->transport->finish($handle, $response, $errno, $this->timeout),
                        $targets[$index],
                        $attempt,
                        $started,
                    );
                    if (\is_array($outcome)) {
                        $results[$index] = new ModelResult($refs[$index], Parse::model(...$outcome), null);
                        continue;
                    }
                } catch (ApiConnectionException $error) {
                    $outcome = $error;
                } catch (InvalidResponseException $error) {
                    $results[$index] = new ModelResult($refs[$index], null, $error);
                    continue;
                }
                $delay = $this->retryDelay($outcome, $attempt);
                if ($delay !== null) {
                    $this->logRetry($targets[$index], $outcome, $attempt, $delay);
                    $waiting[$index] = [$attempt + 1, self::now() + (int) (self::wait($delay) * 1e9)];
                    continue;
                }
                $results[$index] = new ModelResult($refs[$index], null, $outcome);
                if (
                    $fatal === null
                    && ($outcome instanceof AuthenticationException
                        || $outcome instanceof PermissionDeniedException
                        || $outcome instanceof QuotaExceededException)
                ) {
                    $fatal = $outcome;
                }
            }
            if (!$finished && $active > 0) {
                $until = $waiting === [] ? 1.0 : (min(array_column($waiting, 1)) - self::now()) / 1e9;
                if (curl_multi_select($multi, max(0.0, min(1.0, $until))) === -1) {
                    usleep(1000);
                }
            }
        }
        ksort($results);

        return array_values($results);
    }

    /**
     * Starts one batch request.
     *
     * @param list<\CurlHandle> $idle Reusable handles.
     * @param list<string> $headers
     *
     * @return array<int, array{int, int, \CurlHandle, Response, int}> The running entry, keyed by handle id.
     */
    private function launch(
        \CurlMultiHandle $multi,
        array &$idle,
        int $index,
        int $attempt,
        string $target,
        #[\SensitiveParameter]
        array $headers,
    ): array {
        $handle = array_pop($idle) ?? $this->transport->newHandle();
        $response = $this->transport->prepare($handle, $this->baseUrl . $target, $headers, $this->timeout);
        curl_multi_add_handle($multi, $handle);

        return [spl_object_id($handle) => [$index, $attempt, $handle, $response, self::now()]];
    }

    /**
     * Logs an answer; returns the decoded body of a 2xx answer, else its error.
     *
     * @return array{array<mixed>, ResponseMeta}|ApiException
     *
     * @throws InvalidResponseException On a 2xx answer that is not a JSON object or is too large.
     */
    private function settle(Response $response, string $target, int $attempt, int $started): array|ApiException
    {
        $milliseconds = intdiv(self::now() - $started, 1_000_000);
        $this->log(
            'debug',
            \sprintf('polyzon: GET %s -> %d in %d ms (attempt %d)', $target, $response->status, $milliseconds, $attempt + 1),
            ['method' => 'GET', 'path' => $target, 'status' => $response->status, 'attempt' => $attempt + 1, 'durationMs' => $milliseconds],
        );
        $body = $response->tooLarge ? null : self::jsonObject($response->body);
        if ($response->status < 200 || $response->status >= 300) {
            return ApiException::fromResponse($response->status, $body, $response->headers);
        }
        $meta = self::meta($response->headers);
        if ($body === null) {
            throw new InvalidResponseException(
                $response->tooLarge ? 'Response body exceeds 8 MiB' : 'Response body is not a JSON object',
                $response->status,
                $meta->requestId,
            );
        }

        return [$body, $meta];
    }

    /** Seconds to wait before retrying after `$error`, or null when it is final. */
    private function retryDelay(ApiException|ApiConnectionException $error, int $attempt): ?float
    {
        if ($attempt >= $this->maxRetries) {
            return null;
        }
        if ($error instanceof ApiException) {
            $retryable = \in_array($error->status, [500, 502, 503, 504], true)
                || ($error->status === 429 && $error->errorCode === 'rate_limited');
            if (!$retryable) {
                return null;
            }
            if ($error->retryAfter !== null) {
                return $error->retryAfter > self::MAX_RETRY_AFTER ? null : $error->retryAfter;
            }
        }
        $random = Hooks::$random !== null ? (Hooks::$random)() : mt_rand() / mt_getrandmax();

        return min(8.0, 0.5 * 2 ** $attempt) * (0.5 + 0.5 * $random);
    }

    private function logRetry(string $target, PolyzonException $error, int $attempt, float $delay): void
    {
        $this->log(
            'warning',
            \sprintf('polyzon: GET %s failed (%s); retry %d of %d in %.2f s', $target, $error->getMessage(), $attempt + 1, $this->maxRetries, $delay),
            ['method' => 'GET', 'path' => $target, 'attempt' => $attempt + 1, 'delay' => $delay, 'error' => $error::class],
        );
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }

    /** @return list<string> The request headers: exactly Authorization, Accept and User-Agent. */
    private function headers(): array
    {
        return ['Authorization: Bearer ' . $this->apiKey(), 'Accept: application/json', 'User-Agent: ' . $this->userAgentHeader];
    }

    private function apiKey(): string
    {
        return self::$apiKeys[$this] ?? '';
    }

    /** Monotonic clock, nanoseconds. */
    private static function now(): int
    {
        return (int) hrtime(true);
    }

    /** The seconds actually waited for a retry delay (a test seam). */
    private static function wait(float $delay): float
    {
        return Hooks::$wait !== null ? (Hooks::$wait)($delay) : $delay;
    }

    private static function mask(#[\SensitiveParameter] string $apiKey): string
    {
        return substr($apiKey, 0, min(8, intdiv(\strlen($apiKey), 2))) . '…';
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return non-empty-string */
    private static function checkBaseUrl(string $url): string
    {
        $parts = preg_match('/^[\x21-\x7E]+$/D', $url) === 1 && strpbrk($url, '?#@\\') === false ? parse_url($url) : false;
        $scheme = strtolower(\is_array($parts) ? $parts['scheme'] ?? '' : '');
        $host = strtolower(\is_array($parts) ? $parts['host'] ?? '' : '');
        if (
            !\is_array($parts)
            || $host === ''
            || !($scheme === 'https' || ($scheme === 'http' && \in_array($host, self::LOOPBACK_HOSTS, true)))
        ) {
            throw new \InvalidArgumentException(
                'baseUrl must be an https:// URL (http:// only for localhost, 127.0.0.1 or [::1]) without credentials, query or fragment',
            );
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
    }

    /**
     * Validates the search arguments; unset ones are omitted.
     *
     * @param array<mixed>|null $sources
     * @param array<mixed>|null $formats
     * @param array<mixed>|null $licenses
     *
     * @return array<string, string>
     */
    private static function searchQuery(
        string $q,
        ?int $page,
        ?int $size,
        ?string $sort,
        ?bool $free,
        ?array $sources,
        ?array $formats,
        ?array $licenses,
        ?string $lang,
    ): array {
        if (trim($q) === '') {
            throw new \InvalidArgumentException('q must be a non-empty string');
        }
        $query = ['q' => $q];
        if ($page !== null) {
            $query['page'] = (string) self::positive($page, 'page');
        }
        if ($size !== null) {
            $query['size'] = (string) self::positive($size, 'size');
        }
        if ($sort !== null) {
            $query['sort'] = self::nonEmpty($sort, 'sort');
        }
        if ($free === true) {
            $query['free'] = '1';
        }
        foreach (['sources' => $sources, 'formats' => $formats, 'license' => $licenses] as $name => $values) {
            $joined = self::csv($values, $name === 'license' ? 'licenses' : $name);
            if ($joined !== '') {
                $query[$name] = $joined;
            }
        }
        if ($lang !== null) {
            $query['lang'] = self::nonEmpty($lang, 'lang');
        }

        return $query;
    }

    /** @param array<mixed>|null $values */
    private static function csv(?array $values, string $name): string
    {
        foreach ($values ?? [] as $value) {
            if (!\is_string($value) || $value === '' || str_contains($value, ',')) {
                throw new \InvalidArgumentException("{$name} must be a list of non-empty strings without commas");
            }
        }

        return implode(',', $values ?? []);
    }

    /** @return array<string, string> */
    private static function langQuery(?string $lang): array
    {
        return $lang === null ? [] : ['lang' => self::nonEmpty($lang, 'lang')];
    }

    /** @param array<string, string> $query */
    private static function target(string $path, array $query): string
    {
        return $query === [] ? $path : $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function modelPath(string $source, string $id): string
    {
        if (preg_match('/^[a-z0-9]{1,32}$/D', $source) !== 1) {
            throw new \InvalidArgumentException('source must match /^[a-z0-9]{1,32}$/');
        }
        // `.` and `..` would be normalised away by URL handling: a path traversal.
        if (preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $id) !== 1 || $id === '.' || $id === '..') {
            throw new \InvalidArgumentException('id must match /^[A-Za-z0-9._-]{1,255}$/ and not be "." or ".."');
        }

        return '/v1/models/' . rawurlencode($source) . '/' . rawurlencode($id);
    }

    private static function ref(mixed $ref): ModelRef
    {
        if ($ref instanceof ModelRef) {
            return $ref;
        }
        if ($ref instanceof SearchItem || $ref instanceof Model) {
            return new ModelRef($ref->source, $ref->id);
        }
        if (\is_array($ref) && array_is_list($ref) && \count($ref) === 2 && \is_string($ref[0]) && \is_string($ref[1])) {
            return new ModelRef($ref[0], $ref[1]);
        }

        throw new \InvalidArgumentException('each ref must be a ModelRef, a SearchItem, a Model or a [source, id] pair');
    }

    private static function positive(int $value, string $name): int
    {
        if ($value < 1) {
            throw new \InvalidArgumentException("{$name} must be at least 1");
        }

        return $value;
    }

    private static function nonEmpty(string $value, string $name): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException("{$name} must not be empty");
        }

        return $value;
    }

    /** @return array<mixed>|null The body if it is a JSON object, else null. */
    private static function jsonObject(string $body): ?array
    {
        try {
            $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }

        // A JSON object and an empty JSON array decode alike; the first character tells them apart.
        return \is_array($value) && str_starts_with(ltrim($body, " \t\r\n"), '{') ? $value : null;
    }

    /** @param array<string, string> $headers */
    private static function meta(array $headers): ResponseMeta
    {
        $number = static function (string $name) use ($headers): ?int {
            $value = trim($headers[$name] ?? '');

            return preg_match('/^\d{1,18}$/D', $value) === 1 ? (int) $value : null;
        };
        $limit = $number('x-ratelimit-limit');
        $remaining = $number('x-ratelimit-remaining');
        $resetAt = $number('x-ratelimit-reset');
        $requestId = $headers['x-request-id'] ?? '';

        return new ResponseMeta(
            $requestId !== '' ? $requestId : null,
            $limit !== null && $remaining !== null && $resetAt !== null ? new RateLimit($limit, $remaining, $resetAt) : null,
        );
    }
}
