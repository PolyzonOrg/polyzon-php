<?php

declare(strict_types=1);

namespace Polyzon\Tests;

use Polyzon\Exception\InternalServerException;
use Polyzon\SearchItem;

final class PaginationTest extends TestCase
{
    public function testWalksPagesUntilHasMoreIsFalseKeepingItemsInOrder(): void
    {
        $url = self::scenario();
        $items = iterator_to_array(self::client($url)->searchAll('pages:3', size: 2, sort: 'newest'));

        self::assertSame(['tv-1-1', 'tv-1-2', 'tv-2-1', 'tv-2-2', 'tv-3-1', 'tv-3-2'], array_map(static fn(SearchItem $item): string => $item->id, $items));
        self::assertSame(range(0, 5), array_keys($items));
        $requests = self::requests($url);
        self::assertSame(['1', '2', '3'], array_map(static fn(array $request): string => $request['query']['page'], $requests));
        self::assertSame(['q' => 'pages:3', 'page' => '1', 'size' => '2', 'sort' => 'newest'], $requests[0]['query']);
    }

    public function testStartsAtTheGivenPage(): void
    {
        $url = self::scenario();
        $items = iterator_to_array(self::client($url)->searchAll('pages:3', page: 2));

        self::assertCount(4, $items);
        self::assertSame(['2', '3'], array_map(static fn(array $request): string => $request['query']['page'], self::requests($url)));
    }

    public function testStopsAtAnEmptyPageEvenIfHasMoreSaysOtherwise(): void
    {
        $url = self::scenario();
        $items = iterator_to_array(self::client($url)->searchAll('emptyat:3'));

        self::assertCount(2, $items);
        self::assertCount(3, self::requests($url));
    }

    public function testStopsAtPage100TheServerLimit(): void
    {
        $url = self::scenario();
        $count = 0;
        foreach (self::client($url)->searchAll('endless', page: 98) as $item) {
            ++$count;
        }

        self::assertSame(3, $count);
        self::assertSame(['98', '99', '100'], array_map(static fn(array $request): string => $request['query']['page'], self::requests($url)));
    }

    public function testIsLazy(): void
    {
        $url = self::scenario();
        $pages = self::client($url)->searchAll('pages:5', size: 2);
        self::assertSame([], self::requests($url));

        $pages->current();
        self::assertCount(1, self::requests($url));
        $pages->next();
        $pages->next();
        self::assertCount(2, self::requests($url));
    }

    public function testInvalidArgumentsThrowAtOnce(): void
    {
        $client = self::client(self::scenario());

        // The generators are never iterated: the arguments are checked by the call itself.
        // (Closures rather than arrow functions: PHPStan 2.3 misreads arrow functions returning generators.)
        $calls = [
            static function () use ($client): iterable {
                return $client->searchAll('');
            },
            static function () use ($client): iterable {
                return $client->searchAll('x', page: 0);
            },
            static function () use ($client): iterable {
                return $client->searchAll('x', formats: ['a,b']);
            },
        ];
        foreach ($calls as $call) {
            self::catchError(\InvalidArgumentException::class, $call);
        }
    }

    public function testRequestErrorsSurfaceWhileIteratingAfterTheItemsAlreadyYielded(): void
    {
        $url = self::scenario([['ok' => true], ['status' => 500, 'problem' => 'internal']]);
        $seen = [];

        self::catchError(InternalServerException::class, static function () use ($url, &$seen): void {
            foreach (self::client($url, maxRetries: 0)->searchAll('pages:3') as $item) {
                $seen[] = $item->id;
            }
        });
        self::assertSame(['tv-1-1', 'tv-1-2'], $seen);
    }
}
