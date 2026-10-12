<?php

declare(strict_types=1);

namespace Polyzon\Internal;

use Polyzon\Exception\ApiConnectionException;
use Polyzon\Exception\ApiTimeoutException;

/**
 * libcurl plumbing shared by a client and the clients derived from it: one reusable easy
 * handle for sequential requests, extra handles for curl_multi batches, a shared connection
 * cache (keep-alive), the proxy and TLS settings, and the body cap.
 *
 * @internal
 */
final class Transport
{
    /** Response bodies above this many bytes are rejected without buffering the rest. */
    public const MAX_BODY_BYTES = 8 * 1024 * 1024;

    /** Upper bound of the connect phase, seconds (the attempt timeout still applies). */
    private const CONNECT_TIMEOUT = 10.0;

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private const PROXY_PORTS = ['http' => 80, 'https' => 443, 'socks5' => 1080, 'socks5h' => 1080];

    /**
     * Proxy credentials, kept out of the object's properties so that var_export(), print_r()
     * and serializers never see them.
     *
     * @var \WeakMap<self, array{non-empty-string, string}>|null
     */
    private static ?\WeakMap $credentials = null;

    private readonly \CurlShareHandle $share;

    /** The easy handle of sequential requests, created on first use. */
    private ?\CurlHandle $handle = null;

    /**
     * @param array<int, mixed> $options libcurl options set on every handle.
     */
    private function __construct(private readonly array $options)
    {
        $this->share = curl_share_init();
        // Keep-alive connections, DNS answers and TLS sessions are shared by every handle.
        foreach (['CURL_LOCK_DATA_CONNECT', 'CURL_LOCK_DATA_DNS', 'CURL_LOCK_DATA_SSL_SESSION'] as $data) {
            if (\defined($data)) {
                curl_share_setopt($this->share, CURLSHOPT_SHARE, \constant($data));
            }
        }
    }

    /**
     * Validates the transport options and builds the shared transport.
     *
     * @param string $baseUrl The validated base URL.
     * @param string|false|null $proxy Explicit proxy URL; false — no proxy; null — the environment.
     * @param string|null $caBundle Path of a PEM CA bundle that replaces the default trust store.
     *
     * @throws \InvalidArgumentException On an invalid proxy URL or an unreadable CA bundle.
     */
    public static function create(string $baseUrl, #[\SensitiveParameter] string|false|null $proxy, ?string $caBundle): self
    {
        $http = str_starts_with($baseUrl, 'http://');
        $version = curl_version();
        $http2 = \is_array($version) && \is_int($version['features'] ?? null) && ($version['features'] & CURL_VERSION_HTTP2) !== 0;
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY_SSL_VERIFYPEER => true,
            CURLOPT_PROXY_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            // Headers set by the SDK (Authorization!) never go to a proxy in a CONNECT request.
            CURLOPT_HEADEROPT => CURLHEADER_SEPARATE,
            // Plain HTTP (loopback only) stays HTTP/1.1, without an h2c upgrade.
            CURLOPT_HTTP_VERSION => !$http && $http2 ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
        ];
        $protocols = \defined('CURLOPT_PROTOCOLS_STR') ? \constant('CURLOPT_PROTOCOLS_STR') : null; // PHP 8.3+
        if (\is_int($protocols)) {
            $options[$protocols] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($caBundle !== null) {
            if ($caBundle === '' || !is_file($caBundle) || !is_readable($caBundle)) {
                throw new \InvalidArgumentException('caBundle must be the path of a readable PEM file');
            }
            $options[CURLOPT_CAINFO] = $caBundle;
            $options[CURLOPT_PROXY_CAINFO] = $caBundle;
        }

        $credentials = null;
        if (\is_string($proxy)) {
            [$options[CURLOPT_PROXY], $credentials] = self::parseProxy($proxy);
            // An explicit proxy applies to every host, whatever NO_PROXY says.
            $options[CURLOPT_NOPROXY] = '';
            if ($credentials !== null) {
                $options[CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
            }
        } elseif ($proxy === false || \in_array(strtolower((string) parse_url($baseUrl, PHP_URL_HOST)), self::LOOPBACK_HOSTS, true)) {
            // No proxy at all; loopback targets are never sent to an environment proxy.
            $options[CURLOPT_PROXY] = '';
        }
        // Otherwise libcurl reads https_proxy / HTTPS_PROXY, all_proxy and no_proxy itself.
        // The SDK never reads HTTP_PROXY: under CGI that name can come from a request header
        // ("httpoxy"), and plain-http targets are loopback-only anyway.

        $transport = new self($options);
        if ($credentials !== null) {
            self::$credentials ??= new \WeakMap();
            self::$credentials[$transport] = $credentials;
        }

        return $transport;
    }

    /**
     * Sends one GET over the reusable easy handle and reads the whole answer.
     *
     * @param non-empty-string $url
     * @param list<string> $headers Request header lines.
     *
     * @throws ApiConnectionException When no HTTP answer arrived (ApiTimeoutException on timeout).
     */
    public function send(string $url, #[\SensitiveParameter] array $headers, float $timeout): Response
    {
        $handle = $this->handle ??= $this->newHandle();
        $response = $this->prepare($handle, $url, $headers, $timeout);
        curl_exec($handle);

        return $this->finish($handle, $response, curl_errno($handle), $timeout);
    }

    /**
     * A new easy handle with the transport options (for curl_multi batches).
     *
     * @throws ApiConnectionException When libcurl rejects an option.
     */
    public function newHandle(): \CurlHandle
    {
        $handle = curl_init();
        if ($handle === false || !curl_setopt_array($handle, [CURLOPT_SHARE => $this->share] + $this->options)) {
            throw new ApiConnectionException('Could not configure libcurl' . ($handle === false ? '' : ': ' . curl_error($handle)));
        }
        $credentials = self::$credentials[$this] ?? null;
        if ($credentials !== null) {
            curl_setopt($handle, CURLOPT_PROXYUSERNAME, $credentials[0]);
            if ($credentials[1] !== '') {
                curl_setopt($handle, CURLOPT_PROXYPASSWORD, $credentials[1]);
            }
        }

        return $handle;
    }

    /**
     * Sets up `$handle` for one GET; the returned response collects the answer.
     *
     * @param non-empty-string $url
     * @param list<string> $headers Request header lines.
     */
    public function prepare(\CurlHandle $handle, string $url, #[\SensitiveParameter] array $headers, float $timeout): Response
    {
        $response = new Response();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($timeout, self::CONNECT_TIMEOUT) * 1000),
            CURLOPT_HEADERFUNCTION => $response->onHeader(...),
            CURLOPT_WRITEFUNCTION => $response->onBody(...),
        ]);

        return $response;
    }

    /**
     * Turns a finished transfer into its response, or throws its transport error.
     *
     * @param int $errno The libcurl result code of the transfer.
     *
     * @throws ApiConnectionException When no complete HTTP answer arrived.
     */
    public function finish(\CurlHandle $handle, Response $response, int $errno, float $timeout): Response
    {
        if ($errno === CURLE_OK || $response->tooLarge) {
            return $response;
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new ApiTimeoutException(\sprintf('Request timed out after %s s.', $timeout));
        }
        $error = curl_error($handle);
        $message = $error !== '' ? $error : (string) curl_strerror($errno);
        $credentials = self::$credentials[$this] ?? null;
        if ($credentials !== null) {
            $secrets = array_filter([rawurlencode($credentials[1]), $credentials[1]], static fn(string $secret): bool => $secret !== '');
            $message = str_replace($secrets, '***', $message);
        }

        throw new ApiConnectionException(\sprintf('Connection error: %s (cURL error %d)', $message, $errno));
    }

    /**
     * Validates a proxy URL. The message never echoes it: it may hold a password.
     *
     * @return array{string, array{non-empty-string, string}|null} The URL without credentials, and the credentials.
     */
    private static function parseProxy(#[\SensitiveParameter] string $proxy): array
    {
        $parts = preg_match('/^[\x21-\x7E]+$/D', $proxy) === 1 ? parse_url($proxy) : false;
        $scheme = strtolower(\is_array($parts) ? $parts['scheme'] ?? '' : '');
        if (
            !\is_array($parts)
            || !isset(self::PROXY_PORTS[$scheme], $parts['host'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !\in_array($parts['path'] ?? '/', ['', '/'], true)
        ) {
            throw new \InvalidArgumentException(
                'proxy must be an http://, https://, socks5:// or socks5h:// URL such as http://user:pass@proxy.example.com:3128',
            );
        }
        $url = \sprintf('%s://%s:%d', $scheme, $parts['host'], $parts['port'] ?? self::PROXY_PORTS[$scheme]);
        $user = rawurldecode($parts['user'] ?? '');
        $credentials = $user !== '' ? [$user, rawurldecode($parts['pass'] ?? '')] : null;

        return [$url, $credentials];
    }
}
