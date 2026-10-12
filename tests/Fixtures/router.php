<?php

declare(strict_types=1);

/*
 * Mock Polyzon API for PHP's built-in server: `php -S 127.0.0.1:<port> router.php`.
 *
 * Requests go to /s/<nonce>/v1/...; every test uses its own nonce. Each request is appended
 * to <dir>/<nonce>.log as a JSON line (uri, route, headers, remote port, protocol). The
 * answer comes from <dir>/<nonce>.json when the test wrote one:
 *
 *   {"responses": [spec, ...], "routes": {"/v1/models/a/b": [spec, ...]}}
 *
 * The n-th request of a route gets the n-th spec (the last one repeats). A spec holds
 * "ok" (the default answer), or "status" with "problem" (code or fields), "json" or "body",
 * plus optional "headers", "sleep" (ms), "big" (bytes of body), "endless", "truncate" and
 * "bare" (no default headers). Without a spec the router answers like the real API.
 * Requests in flight are counted in <dir>/<nonce>.inflight ("current max").
 */

$dir = (string) getenv('PZ_MOCK_DIR');
$fixtures = require __DIR__ . '/fixtures.php';
$uri = (string) $_SERVER['REQUEST_URI'];
// A request through a forward proxy test arrives in absolute form: http://host:port/path.
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

if (preg_match('~^/s/([A-Za-z0-9]+)(/.*)$~', $path, $match) !== 1) {
    http_response_code(404);
    echo 'unknown nonce';

    return;
}
[, $nonce, $route] = $match;

// Log the request and find its number under one lock (the server may run several workers).
$log = fopen("{$dir}/{$nonce}.log", 'c+');
flock($log, LOCK_EX);
$seen = array_filter(explode("\n", (string) stream_get_contents($log)));
$routeCount = count(array_filter($seen, static fn(string $line): bool => (json_decode($line, true)['route'] ?? null) === $route));
fseek($log, 0, SEEK_END);
fwrite($log, json_encode([
    'uri' => $uri,
    'route' => $route,
    'query' => $query,
    'headers' => array_change_key_case(getallheaders()),
    'port' => (int) $_SERVER['REMOTE_PORT'],
    'protocol' => $_SERVER['SERVER_PROTOCOL'],
    'method' => $_SERVER['REQUEST_METHOD'],
    'time' => microtime(true),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
flock($log, LOCK_UN);
fclose($log);

$inflight = static function (int $delta) use ($dir, $nonce): void {
    $file = fopen("{$dir}/{$nonce}.inflight", 'c+');
    flock($file, LOCK_EX);
    [$current, $max] = array_map('intval', explode(' ', (string) stream_get_contents($file) ?: '0 0') + [0, 0]);
    $current += $delta;
    $max = max($max, $current);
    ftruncate($file, 0);
    rewind($file);
    fwrite($file, "{$current} {$max}");
    flock($file, LOCK_UN);
    fclose($file);
};
$inflight(1);

$scenario = is_file("{$dir}/{$nonce}.json") ? json_decode((string) file_get_contents("{$dir}/{$nonce}.json"), true) : [];
$specs = $scenario['routes'][$route] ?? $scenario['responses'] ?? [['ok' => true]];
$index = isset($scenario['routes'][$route]) ? $routeCount : count($seen);
$spec = $specs[min($index, count($specs) - 1)];

usleep((int) ($spec['sleep'] ?? 0) * 1000);

$status = (int) ($spec['status'] ?? 200);
$headers = $spec['headers'] ?? [];
$body = '';
if (isset($spec['problem'])) {
    $problem = is_array($spec['problem']) ? $spec['problem'] : ['code' => $spec['problem']];
    $body = json_encode($problem + ['type' => 'about:blank', 'title' => 'Error', 'status' => $status, 'detail' => 'Ошибка ' . $status], JSON_UNESCAPED_UNICODE);
    $headers += ['content-type' => 'application/problem+json; charset=utf-8', 'x-request-id' => 'req-err'];
} elseif (array_key_exists('json', $spec)) {
    $body = json_encode($spec['json'], JSON_UNESCAPED_UNICODE);
} elseif (isset($spec['body'])) {
    $body = (string) $spec['body'];
} elseif (isset($spec['big'])) {
    $body = str_repeat('a', (int) $spec['big']);
} elseif (!isset($spec['endless'])) {
    [$status, $data] = defaultAnswer($route, $query, $fixtures);
    $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($status !== 200) {
        $headers += ['content-type' => 'application/problem+json; charset=utf-8'];
    }
}
if (!isset($spec['bare'])) {
    $headers += [
        'content-type' => 'application/json; charset=utf-8',
        'x-request-id' => 'req-123',
        'x-ratelimit-limit' => '200',
        'x-ratelimit-remaining' => '163',
        'x-ratelimit-reset' => '1791590400',
    ];
}

http_response_code($status);
foreach ($headers as $name => $value) {
    header("{$name}: {$value}");
}
if (isset($spec['truncate'])) {
    // Promise more than is sent, then drop the connection.
    header('Content-Length: ' . (strlen($body) + 1000));
}
if (isset($spec['endless'])) {
    // Streams until the client hangs up (or 64 MiB, so a broken client cannot hang the suite).
    $chunk = str_repeat('a', 65536);
    for ($sent = 0; $sent < 64 << 20 && !connection_aborted(); $sent += 65536) {
        echo $chunk;
        flush();
    }
} else {
    echo $body;
}
$inflight(-1);

/**
 * The answer of the real API for a route.
 *
 * @param array<mixed> $query
 * @param array<string, mixed> $fixtures
 *
 * @return array{int, mixed}
 */
function defaultAnswer(string $route, array $query, array $fixtures): array
{
    $q = (string) ($query['q'] ?? '');
    $page = (int) ($query['page'] ?? 1);
    $item = static fn(string $id): array => ['id' => $id] + $fixtures['item'];
    if ($route === '/v1/search' && preg_match('/^pages:(\d+)$/', $q, $m) === 1) {
        return [200, ['page' => $page, 'hasMore' => $page < (int) $m[1], 'items' => [$item("tv-{$page}-1"), $item("tv-{$page}-2")]] + $fixtures['search']];
    }
    if ($route === '/v1/search' && preg_match('/^emptyat:(\d+)$/', $q, $m) === 1) {
        return [200, ['page' => $page, 'hasMore' => true, 'items' => $page === (int) $m[1] ? [] : [$item("tv-{$page}")]] + $fixtures['search']];
    }
    if ($route === '/v1/search') {
        return [200, ['page' => $page] + $fixtures['search']];
    }
    if (preg_match('~^/v1/models/([^/]+)/([^/]+)$~', $route, $m) === 1) {
        return [200, ['source' => rawurldecode($m[1]), 'id' => rawurldecode($m[2])] + $fixtures['model']];
    }
    if ($route === '/v1/me' || $route === '/v1/limits') {
        return [200, $fixtures[substr($route, 4)]];
    }

    return [404, ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'code' => 'not_found', 'detail' => 'Метод не найден.']];
}
