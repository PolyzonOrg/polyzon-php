<?php

declare(strict_types=1);

/*
 * Raw socket servers for the network tests; prints the bound port, then serves one
 * connection at a time and appends JSON lines (connections, requests) to <log>.
 *
 *   php socket-server.php tls <log> <cert.pem> <key.pem>   keep-alive HTTPS server (the `me` example)
 *   php socket-server.php proxy <log> <proxy-authorization or ''> [<cert.pem> <key.pem>]
 *       CONNECT proxy that tunnels to 127.0.0.1:<port> whatever host is asked for; with a
 *       certificate it is an https:// proxy.
 *   php socket-server.php batch <log>   event-loop HTTP server for the batch tests: answers every
 *       connection concurrently, so it sees exactly how many requests the client has in flight.
 *       Model ids: `…-d<ms>…` answers after <ms> (`once-…` only the first time), `x401`
 *       answers 401 invalid_token at once; every other path gets the model example at once.
 */

[, $mode, $logFile] = $argv;
$fixtures = require __DIR__ . '/fixtures.php';
$log = static function (array $entry) use ($logFile): void {
    file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
};

[$cert, $key] = match ($mode) {
    'tls' => [$argv[3], $argv[4]],
    'proxy' => [$argv[4] ?? null, $argv[5] ?? null],
    default => [null, null],
};
$proxyAuthorization = $mode === 'proxy' && ($argv[3] ?? '') !== '' ? $argv[3] : null;
$context = stream_context_create($cert !== null
    ? ['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'alpn_protocols' => 'http/1.1']]
    : []);
$server = stream_socket_server(
    ($cert !== null ? 'tls' : 'tcp') . '://127.0.0.1:0',
    $errno,
    $error,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}
echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
flush();

/** Reads a request head: [request line, lower-case headers], or null at the end of the stream. */
function readHead($connection): ?array
{
    $line = fgets($connection);
    if ($line === false || trim($line) === '') {
        return null;
    }
    $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
        [$name, $value] = explode(':', $header, 2) + [1 => ''];
        $headers[strtolower(trim($name))] = trim($value);
    }

    return [trim($line), $headers];
}

/**
 * Copies bytes both ways until either side closes. Reads drain everything available
 * (non-blocking), so data buffered inside a TLS stream is never left behind.
 */
function pipe($a, $b): void
{
    while (true) {
        $read = [$a, $b];
        $write = $except = null;
        if (stream_select($read, $write, $except, 30) < 1) {
            return;
        }
        foreach ($read as $from) {
            $to = $from === $a ? $b : $a;
            stream_set_blocking($from, false);
            while (($data = fread($from, 65536)) !== false && $data !== '') {
                stream_set_blocking($to, true);
                fwrite($to, $data);
            }
            if (feof($from)) {
                return;
            }
        }
    }
}

if ($mode === 'batch') {
    serveBatch($server, $log, $fixtures['model']);
}

/** The batch mode: one select() loop over every connection, answers on timers. */
function serveBatch($server, Closure $log, array $model): never
{
    $clients = [];
    $buffers = [];
    $due = []; // [time, connection, response]
    $seen = []; // requests per model id
    $inflight = 0;
    while (true) {
        $read = [$server, ...$clients];
        $write = $except = null;
        $wait = $due === [] ? null : max(0, min(array_column($due, 0)) - microtime(true));
        stream_select($read, $write, $except, $wait === null ? null : (int) $wait, $wait === null ? null : (int) (fmod($wait, 1) * 1e6));
        foreach ($read as $stream) {
            if ($stream === $server) {
                $client = stream_socket_accept($server, 0);
                if ($client !== false) {
                    $clients[(int) $client] = $client;
                    $buffers[(int) $client] = '';
                }
                continue;
            }
            $data = fread($stream, 65536);
            if ($data === false || $data === '') {
                unset($clients[(int) $stream], $buffers[(int) $stream]);
                fclose($stream);
                continue;
            }
            $buffers[(int) $stream] .= $data;
            while (($end = strpos($buffers[(int) $stream], "\r\n\r\n")) !== false) {
                $line = strtok($buffers[(int) $stream], "\r\n");
                $buffers[(int) $stream] = substr($buffers[(int) $stream], $end + 4);
                $path = explode(' ', (string) $line)[1] ?? '';
                $id = rawurldecode(basename((string) parse_url($path, PHP_URL_PATH)));
                $log(['event' => 'request', 'path' => $path, 'inflight' => ++$inflight, 'time' => microtime(true)]);
                if ($id === 'x401') {
                    $status = '401 Unauthorized';
                    $body = json_encode(['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401, 'code' => 'invalid_token', 'detail' => 'Токен не найден или отозван.'], JSON_UNESCAPED_UNICODE);
                    $delay = 0;
                } else {
                    $status = '200 OK';
                    $body = json_encode(['id' => $id] + $model, JSON_UNESCAPED_UNICODE);
                    $delay = preg_match('/-d(\d+)/', $id, $m) === 1 ? (int) $m[1] : 0;
                    $seen[$id] = ($seen[$id] ?? 0) + 1;
                    if (str_starts_with($id, 'once-') && $seen[$id] > 1) {
                        $delay = 0; // only the first request of a `once-` id is slow
                    }
                }
                $due[] = [microtime(true) + $delay / 1000, $stream, "HTTP/1.1 {$status}\r\nContent-Type: application/json\r\nContent-Length: " . strlen((string) $body) . "\r\n\r\n{$body}"];
            }
        }
        foreach ($due as $key => [$time, $stream, $response]) {
            if ($time <= microtime(true)) {
                unset($due[$key]);
                --$inflight;
                if (is_resource($stream)) {
                    @fwrite($stream, $response);
                }
            }
        }
    }
}

while (true) {
    $connection = @stream_socket_accept($server, -1);
    if ($connection === false) {
        $log(['event' => 'failed']); // e.g. the client rejected our certificate

        continue;
    }
    $log(['event' => 'connection']);
    if ($mode === 'tls') {
        while (($head = readHead($connection)) !== null) {
            $log(['event' => 'request', 'line' => $head[0], 'headers' => $head[1]]);
            $body = json_encode($fixtures['me'], JSON_UNESCAPED_UNICODE);
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nX-Request-ID: req-tls\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}");
        }
    } elseif (($head = readHead($connection)) !== null) {
        $log(['event' => 'proxy', 'line' => $head[0], 'headers' => $head[1]]);
        [$method, $target] = explode(' ', $head[0]) + [1 => ''];
        $upstream = $method === 'CONNECT' ? @stream_socket_client('tcp://127.0.0.1:' . (int) substr((string) strrchr($target, ':'), 1), $errno, $error, 5) : false;
        if ($proxyAuthorization !== null && ($head[1]['proxy-authorization'] ?? null) !== $proxyAuthorization) {
            fwrite($connection, "HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic\r\nContent-Length: 0\r\n\r\n");
        } elseif ($upstream === false) {
            fwrite($connection, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n");
        } else {
            fwrite($connection, "HTTP/1.1 200 Connection Established\r\n\r\n");
            pipe($connection, $upstream);
            fclose($upstream);
        }
    }
    fclose($connection);
}
