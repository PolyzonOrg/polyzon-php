<?php

/*
 * Quick start: who am I, a search, one model card, the remaining quota.
 * Run: composer install && POLYZON_API_KEY=pzk_... php examples/quickstart.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Polyzon\Client;
use Polyzon\Lang;
use Polyzon\Sort;

$client = new Client(); // reads POLYZON_API_KEY

$me = $client->me();
printf("Token \"%s\" of %s\n", $me->token->name, $me->account->displayName ?? 'an account');

$page = $client->search('дракон', size: 5, free: true, sort: Sort::POPULAR);
printf("%d models found (request id %s)\n", $page->total, $page->meta->requestId ?? '-');
foreach ($page->items as $item) {
    printf("- [%s] %s — %s\n", $item->source, $item->title, $item->url);
}

if ($page->items !== []) {
    $first = $page->items[0];
    $model = $client->getModel($first->source, $first->id, lang: Lang::EN);
    printf("%s: license %s, %d images\n", $model->title, $model->license->id ?? 'unknown', \count($model->images));
}

$limits = $client->limits();
printf(
    "Left: %d/%d this minute, %d/%d today\n",
    $limits->perMinute->remaining,
    $limits->perMinute->limit,
    $limits->daily->remaining,
    $limits->daily->limit,
);
