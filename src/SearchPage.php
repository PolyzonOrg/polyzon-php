<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * A page of search results.
 */
final readonly class SearchPage
{
    /** {@see $totalRelation}: {@see $total} is exact. */
    public const RELATION_EQ = 'eq';

    /** {@see $totalRelation}: {@see $total} is a lower bound. */
    public const RELATION_GTE = 'gte';

    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     *
     * @param list<SearchItem> $items
     */
    public function __construct(
        /** The query as the server understood it. */
        public string $query,
        /** The query the server searched for instead (a typo fixed), or null. */
        public ?string $correctedQuery,
        /** A suggested alternative query, or null. */
        public ?string $didYouMean,
        /** Number of matching models (see {@see $totalRelation}). */
        public int $total,
        /** `eq` — {@see $total} is exact, `gte` — it is a lower bound. */
        public string $totalRelation,
        /** Page number. */
        public int $page,
        /** Page size. */
        public int $size,
        /** Whether a next page exists within the 2400-result window. */
        public bool $hasMore,
        /** True when part of the catalog did not answer: the results are incomplete. */
        public bool $partial,
        /**
         * The models of this page.
         *
         * @var list<SearchItem>
         */
        public array $items,
        /** Request id and daily quota of this response. */
        public ResponseMeta $meta,
    ) {}
}
