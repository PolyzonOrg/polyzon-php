<?php

declare(strict_types=1);

namespace Polyzon\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Polyzon\Client;
use Polyzon\Exception\InvalidResponseException;
use Polyzon\ItemStats;
use Polyzon\Lang;
use Polyzon\License;
use Polyzon\ModelRef;
use Polyzon\Price;
use Polyzon\RateLimit;
use Polyzon\SearchPage;
use Polyzon\Sort;
use Polyzon\Source;

final class EndpointsTest extends TestCase
{
    public function testSearchParsesTheDocumentedExampleAndItsMetadata(): void
    {
        $url = self::scenario();
        $page = self::client($url)->search('дракон');

        self::assertSame('дракон', $page->query);
        self::assertNull($page->correctedQuery);
        self::assertNull($page->didYouMean);
        self::assertSame(1873, $page->total);
        self::assertSame(SearchPage::RELATION_EQ, $page->totalRelation);
        self::assertSame([1, 20, true, false], [$page->page, $page->size, $page->hasMore, $page->partial]);
        self::assertCount(1, $page->items);
        $item = $page->items[0];
        self::assertSame(['thingiverse', 'tv-1234567'], [$item->source, $item->id]);
        self::assertSame('Дракон с подвижными сочленениями (пример)', $item->title);
        self::assertSame('https://www.thingiverse.com/thing:1234567', $item->url);
        self::assertSame('https://cdn.thingiverse.com/assets/dragon.jpg', $item->thumbnail);
        self::assertSame('example_maker', $item->creator);
        self::assertSame(License::CC_BY_NC, $item->license);
        self::assertEquals(new Price(Price::FREE, 0.0, null, 'Бесплатно'), $item->price);
        self::assertSame(['stl'], $item->formats);
        self::assertSame(['дракон', 'игрушка'], $item->tags);
        self::assertEquals(new ItemStats(51234, 870, 3120, 61000), $item->stats);
        self::assertSame('2021-01-17T10:21:00Z', $item->publishedAt);
        self::assertSame('req-123', $page->meta->requestId);
        self::assertEquals(new RateLimit(200, 163, 1791590400), $page->meta->rateLimit);
    }

    public function testOnlyQIsSentWhenNothingElseIsSet(): void
    {
        $url = self::scenario();
        self::client($url)->search('vase', free: false);

        self::assertSame('/v1/search?q=vase', substr(self::requests($url)[0]['uri'], \strlen((string) parse_url($url, PHP_URL_PATH))));
    }

    public function testEveryParameterIsEncodedAndListsAreJoinedWithCommas(): void
    {
        $url = self::scenario();
        self::client($url)->search(
            'органайзер для инструментов & co',
            page: 2,
            size: 50,
            sort: Sort::POPULAR,
            free: true,
            sources: [Source::PRINTABLES, Source::THREE_D_TODAY],
            formats: ['stl', '3mf'],
            licenses: [License::CC0, License::CC_BY],
            lang: Lang::EN,
        );

        $request = self::requests($url)[0];
        self::assertSame([
            'q' => 'органайзер для инструментов & co',
            'page' => '2',
            'size' => '50',
            'sort' => 'popular',
            'free' => '1',
            'sources' => 'printables,3dtoday',
            'formats' => 'stl,3mf',
            'license' => 'cc0,cc-by',
            'lang' => 'en',
        ], $request['query']);
        self::assertStringContainsString(
            '?q=%D0%BE%D1%80%D0%B3%D0%B0%D0%BD%D0%B0%D0%B9%D0%B7%D0%B5%D1%80%20%D0%B4%D0%BB%D1%8F%20',
            $request['uri'],
        );
        self::assertStringContainsString('%20%26%20co&page=2&size=50&sort=popular&free=1&sources=printables%2C3dtoday&', $request['uri']);
    }

    public function testUnknownEnumValuesAndEmptyListsPassThrough(): void
    {
        $url = self::scenario();
        self::client($url)->search('x', sort: 'brand-new', sources: [], formats: ['obj'], lang: 'de');

        self::assertSame(['q' => 'x', 'sort' => 'brand-new', 'formats' => 'obj', 'lang' => 'de'], self::requests($url)[0]['query']);
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed}>
     */
    public static function invalidSearchCalls(): iterable
    {
        yield 'empty q' => [static fn(Client $client) => $client->search('')];
        yield 'blank q' => [static fn(Client $client) => $client->search("  \t")];
        yield 'page 0' => [static fn(Client $client) => $client->search('x', page: 0)];
        yield 'negative size' => [static fn(Client $client) => $client->search('x', size: -1)];
        yield 'empty sort' => [static fn(Client $client) => $client->search('x', sort: '')];
        yield 'empty lang' => [static fn(Client $client) => $client->search('x', lang: '')];
        /** @phpstan-ignore argument.type (invalid on purpose) */
        yield 'non-string source' => [static fn(Client $client) => $client->search('x', sources: [1])];
        yield 'empty format' => [static fn(Client $client) => $client->search('x', formats: [''])];
        yield 'comma in license' => [static fn(Client $client) => $client->search('x', licenses: ['cc0,cc-by'])];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('invalidSearchCalls')]
    public function testInvalidSearchArgumentsThrowBeforeAnyRequest(\Closure $call): void
    {
        $url = self::scenario();
        $client = self::client($url);

        self::catchError(\InvalidArgumentException::class, static fn() => $call($client));
        self::assertSame([], self::requests($url));
    }

    public function testGetModelParsesTheDocumentedExample(): void
    {
        $url = self::scenario();
        $model = self::client($url)->getModel('thingiverse', 'tv-1234567', lang: Lang::EN);

        self::assertSame(['thingiverse', 'tv-1234567'], [$model->source, $model->id]);
        self::assertSame('Дракон печатается целиком, без поддержек…', $model->description);
        self::assertSame(['https://cdn.thingiverse.com/assets/dragon.jpg'], $model->images);
        self::assertSame('https://www.thingiverse.com/example_maker', $model->creatorUrl);
        self::assertSame('Toys & Games', $model->category);
        self::assertNotNull($model->license);
        self::assertSame(['cc-by-nc', 'Creative Commons - Attribution - Non-Commercial'], [$model->license->id, $model->license->raw]);
        self::assertSame(
            [51234, 870, 3120, 61000, 410000, 990000],
            [$model->stats->likes, $model->stats->comments, $model->stats->makes, $model->stats->collects, $model->stats->downloads, $model->stats->views],
        );
        self::assertSame(['2021-01-17T10:21:00Z', '2021-02-02T08:00:00Z'], [$model->publishedAt, $model->updatedAt]);
        self::assertEquals([new ModelRef('printables', 'pr-123456')], $model->copies);
        self::assertSame('req-123', $model->meta->requestId);
        self::assertStringEndsWith('/v1/models/thingiverse/tv-1234567?lang=en', self::requests($url)[0]['uri']);
    }

    public function testGetModelWithoutLangSendsNoQueryAndKeepsDotsInIds(): void
    {
        $url = self::scenario();
        $client = self::client($url);
        $client->getModel('printables', 'pr-123456');
        $client->getModel('nih3d', '...');
        $client->getModel('nasa', 'a.b_c-D');
        $client->getModel('x', str_repeat('a', 255));

        $routes = array_column(self::requests($url), 'route');
        self::assertSame(['/v1/models/printables/pr-123456', '/v1/models/nih3d/...', '/v1/models/nasa/a.b_c-D', '/v1/models/x/' . str_repeat('a', 255)], $routes);
        self::assertStringEndsWith('/v1/models/printables/pr-123456', self::requests($url)[0]['uri']);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function invalidModelArguments(): iterable
    {
        yield 'upper-case source' => ['Thingiverse', 'tv-1', null];
        yield 'empty source' => ['', 'tv-1', null];
        yield 'long source' => [str_repeat('a', 33), 'tv-1', null];
        yield 'source with a slash' => ['a/b', 'tv-1', null];
        yield 'empty id' => ['thingiverse', '', null];
        yield 'dot id' => ['thingiverse', '.', null];
        yield 'dot-dot id' => ['thingiverse', '..', null];
        yield 'slash in id' => ['thingiverse', '../me', null];
        yield 'percent in id' => ['thingiverse', '%2e%2e', null];
        yield 'space in id' => ['thingiverse', 'a b', null];
        yield 'newline after id' => ['thingiverse', "tv-1\n", null];
        yield 'long id' => ['thingiverse', str_repeat('a', 256), null];
        yield 'empty lang' => ['thingiverse', 'tv-1', ''];
    }

    #[DataProvider('invalidModelArguments')]
    public function testInvalidModelArgumentsThrowBeforeAnyRequest(string $source, string $id, ?string $lang): void
    {
        $url = self::scenario();
        $client = self::client($url);

        self::catchError(\InvalidArgumentException::class, static fn() => $client->getModel($source, $id, $lang));
        self::assertSame([], self::requests($url));
    }

    public function testMeParsesTheDocumentedExample(): void
    {
        $me = self::client(self::scenario())->me();

        self::assertSame(['0f8c2a4e-…', 'Алексей', '2026-03-14T10:00:00.000Z'], [$me->account->id, $me->account->displayName, $me->account->createdAt]);
        self::assertSame(['7d0e2f8a-…', 'Телеграм-бот', 'pzk_Q7xR2mVb', '2026-10-09T08:15:00.000Z'], [$me->token->id, $me->token->name, $me->token->prefix, $me->token->createdAt]);
        self::assertSame('req-123', $me->meta->requestId);
    }

    public function testLimitsParsesTheDocumentedExample(): void
    {
        $limits = self::client(self::scenario())->limits();

        self::assertSame([20, 1, 19, 1791579180], [$limits->perMinute->limit, $limits->perMinute->used, $limits->perMinute->remaining, $limits->perMinute->resetAt]);
        self::assertSame([200, 37, 163, 1791590400], [$limits->daily->limit, $limits->daily->used, $limits->daily->remaining, $limits->daily->resetAt]);
    }

    public function testAbsentMetadataHeadersGiveNulls(): void
    {
        $me = self::client(self::scenario([['bare' => true, 'json' => self::fixture('me')]]))->me();

        self::assertNull($me->meta->requestId);
        self::assertNull($me->meta->rateLimit);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function brokenRateLimitHeaders(): iterable
    {
        yield 'missing reset' => [['x-ratelimit-limit' => '200', 'x-ratelimit-remaining' => '5']];
        yield 'not a number' => [['x-ratelimit-limit' => 'abc', 'x-ratelimit-remaining' => '5', 'x-ratelimit-reset' => '1']];
        yield 'negative' => [['x-ratelimit-limit' => '200', 'x-ratelimit-remaining' => '-1', 'x-ratelimit-reset' => '1']];
        yield 'fraction' => [['x-ratelimit-limit' => '200', 'x-ratelimit-remaining' => '1.5', 'x-ratelimit-reset' => '1']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('brokenRateLimitHeaders')]
    public function testPartialOrMalformedRateLimitHeadersGiveNull(array $headers): void
    {
        $limits = self::client(self::scenario([['bare' => true, 'headers' => $headers, 'json' => self::fixture('limits')]]))->limits();

        self::assertNull($limits->meta->rateLimit);
    }

    public function testFloatCountsAreTruncatedToIntegers(): void
    {
        $body = ['total' => 12.0, 'page' => 1.9, 'size' => 20, 'items' => [['stats' => ['likes' => 12.0, 'comments' => 7.9, 'makes' => -1.5, 'collects' => 1e30]] + self::fixture('item')]];
        $page = self::client(self::scenario([['body' => (string) json_encode($body)]]))->search('x');

        self::assertSame([12, 1, 20], [$page->total, $page->page, $page->size]);
        self::assertEquals(new ItemStats(12, 7, -1, null), $page->items[0]->stats);
    }

    public function testWrongTypesBecomeDefaultsAndUnknownFieldsAreIgnored(): void
    {
        $body = [
            'query' => 42,
            'correctedQuery' => ['x'],
            'didYouMean' => 'дракоша',
            'total' => '1873',
            'totalRelation' => 'gte',
            'page' => null,
            'hasMore' => 'yes',
            'partial' => 1,
            'brandNew' => ['ignored' => true],
            'items' => [
                'not an object',
                7,
                ['source' => 'printables', 'id' => 'pr-1', 'title' => null, 'price' => 'free', 'formats' => ['stl', 3, null, '3mf'], 'tags' => 'x', 'stats' => [1, 2], 'license' => false],
                ['source' => 'thingiverse', 'id' => 'tv-2', 'price' => ['type' => 'subscription', 'value' => '9.99', 'display' => 5], 'stats' => 'many'],
            ],
        ];
        $page = self::client(self::scenario([['body' => (string) json_encode($body)]]))->search('x');

        self::assertSame(['', null, 'дракоша', 0, 'gte', 0, 0, false, false], [
            $page->query, $page->correctedQuery, $page->didYouMean, $page->total, $page->totalRelation, $page->page, $page->size, $page->hasMore, $page->partial,
        ]);
        self::assertCount(2, $page->items);
        [$first, $second] = $page->items;
        self::assertSame(['printables', 'pr-1', '', '', null, null, null], [$first->source, $first->id, $first->title, $first->url, $first->thumbnail, $first->license, $first->price]);
        self::assertSame(['stl', '3mf'], $first->formats);
        self::assertSame([], $first->tags);
        self::assertEquals(new ItemStats(null, null, null, null), $first->stats);
        self::assertEquals(new Price('subscription', null, null, ''), $second->price);
        self::assertEquals(new ItemStats(null, null, null, null), $second->stats);
    }

    public function testBrokenModelObjectsDegradeGracefully(): void
    {
        $body = [
            'source' => 'thingiverse',
            'id' => 'tv-1',
            'license' => 'cc0',
            'price' => ['type' => 'paid', 'value' => 4.99, 'currency' => 'USD', 'display' => '$4.99'],
            'images' => ['a.jpg', ['b.jpg']],
            'stats' => ['downloads' => 1e3, 'views' => true],
            'copies' => [['source' => 'printables'], 'x', ['source' => 'cults3d', 'id' => 'c-1']],
            'publishedAt' => 1700000000,
        ];
        $model = self::client(self::scenario([['body' => (string) json_encode($body)]]))->getModel('thingiverse', 'tv-1');

        self::assertNull($model->license);
        self::assertEquals(new Price(Price::PAID, 4.99, 'USD', '$4.99'), $model->price);
        self::assertSame(['a.jpg'], $model->images);
        self::assertSame([1000, null], [$model->stats->downloads, $model->stats->views]);
        self::assertEquals([new ModelRef('printables', ''), new ModelRef('cults3d', 'c-1')], $model->copies);
        self::assertNull($model->publishedAt);
        self::assertNull($model->description);
        self::assertSame([], $model->tags);
    }

    public function testMeAndLimitsWithMissingObjects(): void
    {
        $url = self::scenario([['body' => '{"account": null, "token": [1]}'], ['body' => '{"perMinute": {"limit": 20.0}, "daily": "x"}']]);
        $client = self::client($url);
        $me = $client->me();
        $limits = $client->limits();

        self::assertSame(['', null, ''], [$me->account->id, $me->account->displayName, $me->account->createdAt]);
        self::assertSame(['', '', '', ''], [$me->token->id, $me->token->name, $me->token->prefix, $me->token->createdAt]);
        self::assertSame([20, 0, 0, 0], [$limits->perMinute->limit, $limits->perMinute->used, $limits->perMinute->remaining, $limits->perMinute->resetAt]);
        self::assertSame(0, $limits->daily->limit);
    }

    public function testBigIntegersDoNotOverflow(): void
    {
        $page = self::client(self::scenario([['body' => '{"total": 99999999999999999999999, "page": 9007199254740993, "items": []}']]))->search('x');

        self::assertSame(0, $page->total);
        self::assertSame(9007199254740993, $page->page);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'plain text' => ['not json'];
        yield 'HTML' => ['<html><body>ok</body></html>'];
        yield 'a JSON list' => ['[1, 2]'];
        yield 'an empty JSON list' => ['[]'];
        yield 'a JSON string' => ['"ok"'];
        yield 'null' => ['null'];
        yield 'empty' => [''];
        yield 'truncated JSON' => ['{"query": "x"'];
        yield 'nested too deep' => [str_repeat('{"a":', 70) . '1' . str_repeat('}', 70)];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalid2xxBodiesThrowInvalidResponseAndAreNotRetried(string $body): void
    {
        $url = self::scenario([['body' => $body]]);

        $error = self::catchError(InvalidResponseException::class, static fn() => self::client($url)->me());
        self::assertSame(200, $error->status);
        self::assertSame('req-123', $error->requestId);
        self::assertSame('Response body is not a JSON object (request id req-123)', $error->getMessage());
        self::assertCount(1, self::requests($url));
    }

    public function testAnEmptyJsonObjectIsAValidBody(): void
    {
        $me = self::client(self::scenario([['body' => ' {} ']]))->me();

        self::assertSame('', $me->account->id);
    }
}
