# Polyzon PHP SDK

The official PHP client for the [Polyzon public API](https://api.polyzon.org): search 3D models across
Thingiverse, Printables, MakerWorld, Cults3D and 20 more sites, and fetch model cards. It needs nothing but
`ext-curl` and `ext-json`, and comes with immutable typed results, keep-alive connections, parallel batch
requests over `curl_multi`, automatic retries, proxy support and typed exceptions.

[Русская версия](README.ru.md)

## Requirements

- PHP 8.2 or newer (tested on 8.2, 8.3, 8.4 and 8.5) with the `curl` and `json` extensions.
- An API token from [polyzon.org/account](https://polyzon.org/account/) (section «API»).

## Installation

```sh
composer require polyzon/polyzon-php
```

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$client = new Polyzon\Client(); // reads POLYZON_API_KEY

$page = $client->search('дракон', size: 10, free: true);
foreach ($page->items as $item) {
    echo "[{$item->source}] {$item->title} — {$item->url}\n";
}
```

More in [`examples/`](examples): quick start, pagination, parallel batch, proxy
(`php examples/quickstart.php` after `composer install`).

## Authentication

Every request is authorised with your personal token (`pzk_…`). Pass it explicitly or set the
`POLYZON_API_KEY` environment variable:

```php
$client = new Polyzon\Client(apiKey: $token);
```

The default is read with `getenv()`. If your framework loads `.env` files without `putenv()` (Symfony
Dotenv, for example), pass `apiKey: $_ENV['POLYZON_API_KEY']`. The constructor validates the token (1–512
visible ASCII characters) and never prints it: `var_dump($client)` shows `pzk_Q7xR…`.

## Usage

All methods are synchronous and take named arguments. Results are immutable `readonly` objects.

### Search

```php
use Polyzon\Lang;
use Polyzon\License;
use Polyzon\Sort;
use Polyzon\Source;

$page = $client->search(
    'органайзер для инструментов',      // required, Russian or English, up to 200 characters
    page: 1,                            // 1..100
    size: 20,                           // 1..50, page × size ≤ 2400
    sort: Sort::POPULAR,                // relevant | popular | newest | likes | makes | downloads
    free: true,                         // only free models
    sources: [Source::PRINTABLES, Source::THINGIVERSE],
    formats: ['stl', '3mf'],
    licenses: [License::CC0, License::CC_BY],
    lang: Lang::EN,                     // ru (default) | en — machine-translated titles and tags
);

$page->total;          // 1873
$page->totalRelation;  // 'eq' (exact) or 'gte' (lower bound)
$page->hasMore;        // is there a next page within the 2400-result window
$page->partial;        // part of the catalog did not answer
$page->correctedQuery; // the typo-fixed query the server searched for, or null
$page->items[0];       // SearchItem: source, id, title, url, thumbnail, creator, license, price, formats, tags, stats, publishedAt
```

Unset arguments are not sent. `Sort`, `Lang`, `Source` and `License` hold the known values as constants
(plus `ALL`), but every argument and field is a plain string: new sources appear over time.

### Pagination

`searchAll` takes the same arguments and returns a lazy `Generator` of items:

```php
foreach ($client->searchAll('shelf bracket', size: 50) as $i => $item) {
    echo $item->title, "\n";
    if ($i >= 119) {
        break; // 3 pages = 3 requests
    }
}
```

It starts at `page` (default 1) and stops when `hasMore` is false, a page is empty, or at page 100 (the
server's limit). **Every page costs one request of the daily quota**, so use `size: 50` and `break` as soon
as you have enough. Invalid arguments throw at once; request errors are thrown while iterating.

### Model card

```php
$model = $client->getModel('thingiverse', 'tv-1234567', lang: Lang::EN);
$model->description;
$model->images;  // URLs on the source's CDN
$model->license; // ModelLicense { id: 'cc-by-nc', raw: 'Creative Commons - Attribution - Non-Commercial' } or null
$model->copies;  // the same model on other sites: list of ModelRef { source, id }
```

`source` and `id` are the ones from search items. They are validated before any request (`source` matches
`^[a-z0-9]{1,32}$`, `id` matches `^[A-Za-z0-9._-]{1,255}$` and is not `.` or `..`); an invalid value throws
`InvalidArgumentException`.

### Many models at once

```php
$results = $client->getModels($page->items, concurrency: 4, lang: Lang::EN);
foreach ($results as $result) {
    if ($result->isOk()) {
        echo $result->model->title, "\n";
    } else {
        echo "{$result->ref->source}/{$result->ref->id}: {$result->error->getMessage()}\n";
    }
}
```

`getModels` accepts `ModelRef` objects, search items, models or `[source, id]` pairs (any iterable). It runs
real parallel transfers over `curl_multi`, at most `concurrency` at a time (default 4), and returns one
`ModelResult` per reference **in input order**. It never throws because of one model: each result holds
either `model` or `error`, and failed requests are retried like any other. After an
`AuthenticationException`, `PermissionDeniedException` or `QuotaExceededException` the references not yet
started get that same error without a request. Each model costs one request of the daily quota.

### Account and limits

```php
$me = $client->me();         // Me { account: { id, displayName, createdAt }, token: { id, name, prefix, createdAt } }
$limits = $client->limits(); // Limits { perMinute: { limit, used, remaining, resetAt }, daily: { … } }
```

Neither costs daily quota (they still count toward the per-minute limit).

## Response metadata

Every result has a `meta` property:

```php
$page = $client->search('ваза');
$page->meta->requestId; // X-Request-ID — quote it when contacting support
$page->meta->rateLimit; // RateLimit { limit: 200, remaining: 163, resetAt: 1791590400 } — the daily quota, or null
```

## Errors

Every failed request throws a subclass of `Polyzon\Exception\PolyzonException` (a `RuntimeException`):

| Exception                   | When                                                                                                             |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `ApiConnectionException`    | network failure: DNS, TCP, TLS, proxy, connection reset                                                          |
| `ApiTimeoutException`       | an attempt took longer than `timeout` (subclass of `ApiConnectionException`)                                     |
| `InvalidResponseException`  | a 2xx answer whose body is not a JSON object or exceeds 8 MiB                                                    |
| `BadRequestException`       | 400: `bad_request` (see `$e->param`) or `page_too_deep`                                                          |
| `AuthenticationException`   | 401: `unauthorized` (no token) or `invalid_token` (wrong or revoked)                                             |
| `PermissionDeniedException` | 403 `account_action_required`: accept the current terms at [polyzon.org/account](https://polyzon.org/account/)   |
| `NotFoundException`         | 404 `not_found`                                                                                                  |
| `GoneException`             | 410 `gone`: the model was removed at the right holder's request — **delete your copy too**                       |
| `RateLimitException`        | 429 `rate_limited`: more than 20 requests a minute (retried automatically)                                       |
| `QuotaExceededException`    | 429 `quota_exceeded`: the daily quota is spent until 00:00 UTC (subclass of `RateLimitException`, never retried) |
| `InternalServerException`   | 5xx: `unavailable`, `internal`, edge errors                                                                      |
| `ApiException`              | any other HTTP error, including an unexpected 3xx (redirects are never followed); base class of the HTTP errors  |

`ApiException` has the readonly properties `status`, `errorCode` (the stable machine-readable reason, or
`null`; it is not called `code` because `getCode()` already returns the HTTP status), `title`, `detail`
(usually in Russian), `param`, `requestId`, `retryAfter` (seconds or `null`), `headers` and `body`. Its
message reads `401 invalid_token: Токен не найден или отозван. (request id …)`.

```php
use Polyzon\Exception\GoneException;
use Polyzon\Exception\NotFoundException;
use Polyzon\Exception\QuotaExceededException;

try {
    $client->getModel('printables', 'pr-123456');
} catch (GoneException) {
    $db->delete('printables', 'pr-123456');
} catch (NotFoundException) {
    echo "no such model\n";
} catch (QuotaExceededException $e) {
    echo "quota is spent, retry in {$e->retryAfter} s\n";
}
```

Invalid arguments throw the standard `InvalidArgumentException` before any request; it is not a
`PolyzonException`.

## Retries

Failed requests are retried up to `maxRetries` times (default 2) on connection errors, timeouts, HTTP 500,
502, 503, 504 and 429 `rate_limited`. The delay is the server's `Retry-After` when present (seconds or an
HTTP date; at most 60 seconds — if the server asks for longer, the error is thrown at once), otherwise
exponential backoff `min(8 s, 0.5 s × 2^attempt)` with jitter. `quota_exceeded`, a 429 without a code and
other 4xx errors are never retried.

```php
$client = new Polyzon\Client(maxRetries: 5); // or 0 to disable
```

## Timeouts

`timeout` limits each attempt (connect + response), in seconds; default 30, of which connecting may take at
most 10. A timed-out attempt throws `ApiTimeoutException` (after the retries).

```php
$client = new Polyzon\Client(timeout: 10.0);
$quick = $client->withOptions(timeout: 2.0, maxRetries: 0);
```

## Proxies

```php
$client = new Polyzon\Client(proxy: 'http://user:password@proxy.example.com:3128');
```

- `http://`, `https://`, `socks5://` and `socks5h://` (DNS through the proxy) proxies are supported, all
  through libcurl. Credentials in the URL are percent-decoded and sent as Basic proxy authentication; they
  are never logged or put into exception messages. Without a port, 80 / 443 / 1080 is used.
- HTTPS requests go through an HTTP `CONNECT` tunnel with end-to-end TLS to `api.polyzon.org`: the proxy
  never sees your token. The tunnel is kept alive between requests.
- Without the `proxy` option the client uses the environment exactly as libcurl reads it: `https_proxy` /
  `HTTPS_PROXY`, `all_proxy` / `ALL_PROXY` and `no_proxy` / `NO_PROXY`. Loopback hosts are never proxied by
  the environment. `proxy: false` turns every proxy off.
- **httpoxy-safe:** the SDK never reads `HTTP_PROXY`, which a CGI / PHP-FPM request can inject through a
  `Proxy:` header (libcurl ignores the upper-case name too, and plain-HTTP targets are loopback-only).

## TLS and CA certificates

TLS verification (certificate and host name) is always on and cannot be turned off. Behind a TLS-inspecting
corporate proxy, trust its root certificate:

```php
$client = new Polyzon\Client(caBundle: '/etc/ssl/corporate-root-ca.pem');
```

The bundle replaces the default trust store for the API and for an `https://` proxy. Without `caBundle`
libcurl uses the system store or the `curl.cainfo` php.ini setting (on Windows, point `curl.cainfo` at a
`cacert.pem` if PHP has none).

## Concurrency

A client is immutable and keeps connections alive (shared with clients made by `withOptions`), so create
one and reuse it for sequential requests and batches. For parallel requests use `getModels`, which drives
up to `concurrency` transfers at once over `curl_multi` with a sliding window.

```php
// Same connections, different settings — e.g. one client per end-user token:
$forUser = $client->withOptions(apiKey: $userToken, maxRetries: 0);
```

`withOptions` accepts `apiKey`, `timeout`, `maxRetries`, `userAgent` and `logger`; the base URL, proxy and
CA bundle stay those of the original client. Connections close when the last client using them is
garbage-collected.

A client must not be shared between threads (ext-parallel, pthreads): give every thread its own client.
Clients cannot be serialized either.

## Async

PHP has no standard async runtime, so the client is synchronous; `getModels` covers parallel fetching. In
ReactPHP / Amp applications, call it from a worker process or a thread with its own client.

## Logging

```php
$client = new Polyzon\Client(logger: function (string $level, string $message, array $context): void {
    error_log("[{$level}] {$message}");
});

// Any PSR-3 logger (Monolog, …):
$client = new Polyzon\Client(logger: $psrLogger->log(...));
```

The logger gets `debug` lines for every attempt (method, path with query, status, attempt, duration) and
`warning` lines for retries (reason and delay), with the same data in `$context`. It never gets headers or
the token. No PSR package is required.

## Rate limits and quota

- 20 requests per minute per account, all methods.
- 200 requests per UTC day per account for `search` and `getModel` (`me` and `limits` are free). Every
  `searchAll` page and every `getModels` item counts.
- The server charges the quota **before** validating parameters, so even a `400` costs a request.
- Quotas are per account, not per token: several tokens do not multiply them.

Check them with `$client->limits()` or `$result->meta->rateLimit` (the daily window after the request).
`QuotaExceededException::$retryAfter` tells when the day resets.

## Testing your code

Point the client at a local mock: `http://` is accepted for `localhost`, `127.0.0.1` and `[::1]`.

```php
$client = new Polyzon\Client(apiKey: 'test', baseUrl: 'http://127.0.0.1:8080', proxy: false);
```

## User-Agent

Requests send `User-Agent: polyzon-php/1.0.0 (php 8.4.13; linux/x86_64)`. Add your app with
`new Polyzon\Client(userAgent: 'my-bot/2.1')`.

## Versioning

This package follows [Semantic Versioning](https://semver.org). Breaking changes of the public API (the
`Polyzon\` classes, except `Polyzon\Internal\` and members marked `@internal`) only come with a new major
version. Constructors of result classes are internal: new fields may be added in minor versions. See
[CHANGELOG.md](CHANGELOG.md).

## Security

**Never ship your token to browsers or mobile apps — keep it server-side.** Anyone holding it can spend your
quota. The client only talks HTTPS (plain `http://` is accepted for `localhost` only), never follows
redirects, always verifies TLS, caps response bodies at 8 MiB and keeps the token out of `var_dump`,
`print_r`, `var_export`, `json_encode` and stack traces. Report vulnerabilities as described in
[SECURITY.md](SECURITY.md).

## Links

- API documentation and sandbox: https://api.polyzon.org
- Tokens: https://polyzon.org/account/
- Issues: https://github.com/PolyzonOrg/polyzon-php/issues

## License

[MIT](LICENSE)
