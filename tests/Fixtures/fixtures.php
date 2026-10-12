<?php

declare(strict_types=1);

/*
 * Response examples from the server's OpenAPI document (api/src/lib/openapi.ts), shared by
 * the tests and the mock API router.
 */

$item = [
    'source' => 'thingiverse',
    'id' => 'tv-1234567',
    'title' => 'Дракон с подвижными сочленениями (пример)',
    'url' => 'https://www.thingiverse.com/thing:1234567',
    'thumbnail' => 'https://cdn.thingiverse.com/assets/dragon.jpg',
    'creator' => 'example_maker',
    'license' => 'cc-by-nc',
    'price' => ['type' => 'free', 'value' => 0, 'currency' => null, 'display' => 'Бесплатно'],
    'formats' => ['stl'],
    'tags' => ['дракон', 'игрушка'],
    'stats' => ['likes' => 51234, 'comments' => 870, 'makes' => 3120, 'collects' => 61000],
    'publishedAt' => '2021-01-17T10:21:00Z',
];

return [
    'item' => $item,
    'search' => [
        'query' => 'дракон',
        'correctedQuery' => null,
        'didYouMean' => null,
        'total' => 1873,
        'totalRelation' => 'eq',
        'page' => 1,
        'size' => 20,
        'hasMore' => true,
        'partial' => false,
        'items' => [$item],
    ],
    'model' => [
        ...$item,
        'description' => 'Дракон печатается целиком, без поддержек…',
        'images' => ['https://cdn.thingiverse.com/assets/dragon.jpg'],
        'creatorUrl' => 'https://www.thingiverse.com/example_maker',
        'license' => ['id' => 'cc-by-nc', 'raw' => 'Creative Commons - Attribution - Non-Commercial'],
        'category' => 'Toys & Games',
        'stats' => [
            'likes' => 51234,
            'comments' => 870,
            'makes' => 3120,
            'collects' => 61000,
            'downloads' => 410000,
            'views' => 990000,
        ],
        'updatedAt' => '2021-02-02T08:00:00Z',
        'copies' => [['source' => 'printables', 'id' => 'pr-123456']],
    ],
    'me' => [
        'account' => ['id' => '0f8c2a4e-…', 'displayName' => 'Алексей', 'createdAt' => '2026-03-14T10:00:00.000Z'],
        'token' => [
            'id' => '7d0e2f8a-…',
            'name' => 'Телеграм-бот',
            'prefix' => 'pzk_Q7xR2mVb',
            'createdAt' => '2026-10-09T08:15:00.000Z',
        ],
    ],
    'limits' => [
        'perMinute' => ['limit' => 20, 'used' => 1, 'remaining' => 19, 'resetAt' => 1791579180],
        'daily' => ['limit' => 200, 'used' => 37, 'remaining' => 163, 'resetAt' => 1791590400],
    ],
];
