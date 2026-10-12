<?php

declare(strict_types=1);

use Polyzon\Tests\Server;
use Polyzon\Tests\TestCase;

require dirname(__DIR__) . '/vendor/autoload.php';

// The suite controls the proxy environment itself, test by test.
foreach (['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy', 'NO_PROXY', 'no_proxy', 'POLYZON_API_KEY', 'POLYZON_BASE_URL'] as $name) {
    putenv($name);
}

$dir = sys_get_temp_dir() . '/polyzon-php-tests-' . bin2hex(random_bytes(6));
mkdir($dir);
TestCase::$dir = $dir;

$server = Server::mockApi($dir);
TestCase::$mockUrl = "http://127.0.0.1:{$server->port}";

register_shutdown_function(static function () use ($server, $dir): void {
    $server->stop();
    $files = glob("{$dir}/*");
    foreach ($files === false ? [] : $files as $file) {
        unlink($file);
    }
    rmdir($dir);
});
