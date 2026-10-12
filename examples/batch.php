<?php

/*
 * Fetch many model cards in parallel (curl_multi, at most 4 requests in flight). Results
 * come in input order; each holds either the model or the error.
 * Run: composer install && POLYZON_API_KEY=pzk_... php examples/batch.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Polyzon\Client;
use Polyzon\Exception\GoneException;

$client = new Client();

$page = $client->search('органайзер', size: 10);
$results = $client->getModels($page->items, concurrency: 4);

foreach ($results as $result) {
    $ref = "{$result->ref->source}/{$result->ref->id}";
    if ($result->error instanceof GoneException) {
        echo "{$ref}: removed at the right holder's request — delete your copy\n";
    } elseif ($result->error !== null) {
        echo "{$ref}: {$result->error->getMessage()}\n";
    } elseif ($result->model !== null) {
        printf("%s: %s (%d images)\n", $ref, $result->model->title, \count($result->model->images));
    }
}
