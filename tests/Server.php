<?php

declare(strict_types=1);

namespace Polyzon\Tests;

/**
 * A helper server in its own PHP process: the mock API (PHP's built-in server with
 * Fixtures/router.php) or a raw socket server (Fixtures/socket-server.php).
 */
final class Server
{
    /**
     * @param resource $process
     */
    private function __construct(
        private $process,
        public readonly int $port,
        public readonly string $log,
    ) {}

    /** The mock API on 127.0.0.1; requests are logged into `$dir`. */
    public static function mockApi(string $dir): self
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('no free port');
        }
        $port = (int) parse_url('tcp://' . stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);

        $env = getenv() + ['PZ_MOCK_DIR' => $dir];
        $env['PZ_MOCK_DIR'] = $dir;
        if (PHP_OS_FAMILY !== 'Windows') {
            $env['PHP_CLI_SERVER_WORKERS'] = '8'; // parallel requests for the batch tests
        }
        $process = proc_open(
            [PHP_BINARY, '-d', 'output_buffering=0', '-d', 'pcov.enabled=0', '-S', "127.0.0.1:{$port}", __DIR__ . '/Fixtures/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', "{$dir}/server.out", 'a'], 2 => ['file', "{$dir}/server.err", 'a']],
            $pipes,
            null,
            $env,
        );
        if ($process === false) {
            throw new \RuntimeException('could not start the mock API');
        }
        $deadline = microtime(true) + 10;
        do {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 1);
            if ($socket === false && microtime(true) > $deadline) {
                throw new \RuntimeException('the mock API did not start: ' . file_get_contents("{$dir}/server.err"));
            }
            usleep($socket === false ? 50_000 : 0);
        } while ($socket === false);
        fclose($socket);

        return new self($process, $port, '');
    }

    /**
     * A raw socket server: `tls` (keep-alive HTTPS) or `proxy` (CONNECT).
     *
     * @param list<string> $args Extra arguments of socket-server.php.
     */
    public static function socket(string $mode, string $log, array $args = []): self
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'pcov.enabled=0', __DIR__ . '/Fixtures/socket-server.php', $mode, $log, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log . '.err', 'a']],
            $pipes,
        );
        $port = $process === false ? false : fgets($pipes[1]);
        if ($process === false || $port === false) {
            throw new \RuntimeException("could not start the {$mode} server: " . @file_get_contents($log . '.err'));
        }

        return new self($process, (int) $port, $log);
    }

    /**
     * The JSON lines a socket server logged.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $entries = [];
        $lines = is_file($this->log) ? file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        foreach ($lines === false ? [] : $lines as $line) {
            $entry = json_decode($line, true);
            if (\is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
