<?php

/*
 * Through a proxy. HTTPS goes through a CONNECT tunnel: the proxy never sees the token.
 * Without the `proxy` option the client honours https_proxy / HTTPS_PROXY / no_proxy.
 * Run: composer install && POLYZON_API_KEY=pzk_... PROXY_URL=http://user:pass@proxy:3128 php examples/proxy.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Polyzon\Client;

$proxy = getenv('PROXY_URL');

$client = new Client(
    proxy: \is_string($proxy) && $proxy !== '' ? $proxy : 'http://proxy.example.com:3128',
    // A TLS-inspecting corporate proxy needs its root certificate:
    // caBundle: '/etc/ssl/corporate-root-ca.pem',
);

$limits = $client->limits();
printf("Through the proxy: %d requests left today\n", $limits->daily->remaining);
