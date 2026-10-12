<?php

/*
 * Walk search results page by page. Every page costs one request of the daily quota,
 * so use size: 50 and stop as soon as you have enough.
 * Run: composer install && POLYZON_API_KEY=pzk_... php examples/pagination.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Polyzon\Client;
use Polyzon\Lang;
use Polyzon\Source;

$client = new Client();

$items = $client->searchAll(
    'shelf bracket',
    size: 50,
    lang: Lang::EN,
    sources: [Source::PRINTABLES, Source::THINGIVERSE],
    formats: ['stl', '3mf'],
);

foreach ($items as $count => $item) {
    printf("%3d. %s (%s likes)\n", $count + 1, $item->title, $item->stats->likes ?? '?');
    if ($count + 1 >= 120) {
        break; // 3 pages = 3 requests
    }
}
