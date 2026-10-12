<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * One model in search results. Pass its source and id to {@see Client::getModel()}, or the
 * item itself to {@see Client::getModels()}.
 */
final readonly class SearchItem
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     *
     * @param list<string> $formats
     * @param list<string> $tags
     */
    public function __construct(
        /** Source id, e.g. `thingiverse` (see {@see Source}). */
        public string $source,
        /** Model id within Polyzon, e.g. `tv-1234567`. */
        public string $id,
        /** Title. */
        public string $title,
        /** Model page on the source site. */
        public string $url,
        /** Thumbnail URL, or null. */
        public ?string $thumbnail,
        /** Author name, or null. */
        public ?string $creator,
        /** License id (see {@see License}), or null. */
        public ?string $license,
        /** Price, or null when unknown. */
        public ?Price $price,
        /**
         * File formats, e.g. `stl`.
         *
         * @var list<string>
         */
        public array $formats,
        /**
         * Tags.
         *
         * @var list<string>
         */
        public array $tags,
        /** Counters. */
        public ItemStats $stats,
        /** ISO-8601 date as sent by the source (not validated), or null. */
        public ?string $publishedAt,
    ) {}
}
