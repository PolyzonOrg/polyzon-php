<?php

declare(strict_types=1);

namespace Polyzon\Internal;

use Polyzon\Account;
use Polyzon\ItemStats;
use Polyzon\Limits;
use Polyzon\Me;
use Polyzon\Model;
use Polyzon\ModelLicense;
use Polyzon\ModelRef;
use Polyzon\ModelStats;
use Polyzon\Price;
use Polyzon\QuotaWindow;
use Polyzon\ResponseMeta;
use Polyzon\SearchItem;
use Polyzon\SearchPage;
use Polyzon\TokenInfo;

/**
 * Lenient parsing of response bodies. The server is treated as untrusted input: unknown
 * fields are dropped, wrong-typed optional fields become null or an empty list, missing
 * required strings become '' and missing counts 0. Nothing here throws on a wrong type.
 *
 * @internal
 */
final class Parse
{
    /**
     * `GET /v1/search` body → {@see SearchPage}. Items that are not objects are skipped.
     *
     * @param array<mixed> $body
     */
    public static function searchPage(array $body, ResponseMeta $meta): SearchPage
    {
        return new SearchPage(
            self::text($body['query'] ?? null),
            self::textOrNull($body['correctedQuery'] ?? null),
            self::textOrNull($body['didYouMean'] ?? null),
            self::int($body['total'] ?? null),
            self::text($body['totalRelation'] ?? null),
            self::int($body['page'] ?? null),
            self::int($body['size'] ?? null),
            ($body['hasMore'] ?? null) === true,
            ($body['partial'] ?? null) === true,
            array_map(self::item(...), self::objects($body['items'] ?? null)),
            $meta,
        );
    }

    /**
     * `GET /v1/models/{source}/{id}` body → {@see Model}.
     *
     * @param array<mixed> $body
     */
    public static function model(array $body, ResponseMeta $meta): Model
    {
        $stats = self::object($body['stats'] ?? null) ?? [];
        $license = self::object($body['license'] ?? null);

        return new Model(
            self::text($body['source'] ?? null),
            self::text($body['id'] ?? null),
            self::text($body['title'] ?? null),
            self::text($body['url'] ?? null),
            self::textOrNull($body['description'] ?? null),
            self::textOrNull($body['thumbnail'] ?? null),
            self::strings($body['images'] ?? null),
            self::textOrNull($body['creator'] ?? null),
            self::textOrNull($body['creatorUrl'] ?? null),
            self::textOrNull($body['category'] ?? null),
            $license === null ? null : new ModelLicense(self::text($license['id'] ?? null), self::textOrNull($license['raw'] ?? null)),
            self::price($body['price'] ?? null),
            self::strings($body['formats'] ?? null),
            self::strings($body['tags'] ?? null),
            new ModelStats(
                self::intOrNull($stats['likes'] ?? null),
                self::intOrNull($stats['comments'] ?? null),
                self::intOrNull($stats['makes'] ?? null),
                self::intOrNull($stats['collects'] ?? null),
                self::intOrNull($stats['downloads'] ?? null),
                self::intOrNull($stats['views'] ?? null),
            ),
            self::textOrNull($body['publishedAt'] ?? null),
            self::textOrNull($body['updatedAt'] ?? null),
            array_map(
                static fn(array $copy): ModelRef => new ModelRef(self::text($copy['source'] ?? null), self::text($copy['id'] ?? null)),
                self::objects($body['copies'] ?? null),
            ),
            $meta,
        );
    }

    /**
     * `GET /v1/me` body → {@see Me}.
     *
     * @param array<mixed> $body
     */
    public static function me(array $body, ResponseMeta $meta): Me
    {
        $account = self::object($body['account'] ?? null) ?? [];
        $token = self::object($body['token'] ?? null) ?? [];

        return new Me(
            new Account(
                self::text($account['id'] ?? null),
                self::textOrNull($account['displayName'] ?? null),
                self::text($account['createdAt'] ?? null),
            ),
            new TokenInfo(
                self::text($token['id'] ?? null),
                self::text($token['name'] ?? null),
                self::text($token['prefix'] ?? null),
                self::text($token['createdAt'] ?? null),
            ),
            $meta,
        );
    }

    /**
     * `GET /v1/limits` body → {@see Limits}.
     *
     * @param array<mixed> $body
     */
    public static function limits(array $body, ResponseMeta $meta): Limits
    {
        return new Limits(self::window($body['perMinute'] ?? null), self::window($body['daily'] ?? null), $meta);
    }

    /** @param array<mixed> $item */
    private static function item(array $item): SearchItem
    {
        $stats = self::object($item['stats'] ?? null) ?? [];

        return new SearchItem(
            self::text($item['source'] ?? null),
            self::text($item['id'] ?? null),
            self::text($item['title'] ?? null),
            self::text($item['url'] ?? null),
            self::textOrNull($item['thumbnail'] ?? null),
            self::textOrNull($item['creator'] ?? null),
            self::textOrNull($item['license'] ?? null),
            self::price($item['price'] ?? null),
            self::strings($item['formats'] ?? null),
            self::strings($item['tags'] ?? null),
            new ItemStats(
                self::intOrNull($stats['likes'] ?? null),
                self::intOrNull($stats['comments'] ?? null),
                self::intOrNull($stats['makes'] ?? null),
                self::intOrNull($stats['collects'] ?? null),
            ),
            self::textOrNull($item['publishedAt'] ?? null),
        );
    }

    private static function window(mixed $value): QuotaWindow
    {
        $window = self::object($value) ?? [];

        return new QuotaWindow(
            self::int($window['limit'] ?? null),
            self::int($window['used'] ?? null),
            self::int($window['remaining'] ?? null),
            self::int($window['resetAt'] ?? null),
        );
    }

    private static function price(mixed $value): ?Price
    {
        $price = self::object($value);
        if ($price === null) {
            return null;
        }
        $amount = $price['value'] ?? null;

        return new Price(
            self::text($price['type'] ?? null),
            (\is_int($amount) || \is_float($amount)) && is_finite($amount) ? (float) $amount : null,
            self::textOrNull($price['currency'] ?? null),
            self::text($price['display'] ?? null),
        );
    }

    /**
     * The value if it is a JSON object (an empty JSON array decodes the same and passes too).
     *
     * @return array<mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        return \is_array($value) && ($value === [] || !array_is_list($value)) ? $value : null;
    }

    /**
     * The JSON objects of a list; anything else is dropped.
     *
     * @return list<array<mixed>>
     */
    private static function objects(mixed $value): array
    {
        return \is_array($value) && array_is_list($value)
            ? array_values(array_filter(array_map(self::object(...), $value), static fn(?array $entry): bool => $entry !== null))
            : [];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return \is_array($value) && array_is_list($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function textOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /** Counts may arrive as floats (`12.0`): truncated toward zero. Out-of-range → null. */
    private static function intOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_float($value) && $value > -9.2e18 && $value < 9.2e18 ? (int) $value : null;
    }

    private static function int(mixed $value): int
    {
        return self::intOrNull($value) ?? 0;
    }
}
