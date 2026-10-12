<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * A model card.
 */
final readonly class Model
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     *
     * @param list<string> $images
     * @param list<string> $formats
     * @param list<string> $tags
     * @param list<ModelRef> $copies
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
        /** Description, or null. */
        public ?string $description,
        /** Thumbnail URL, or null. */
        public ?string $thumbnail,
        /**
         * Image URLs on the CDN of the source.
         *
         * @var list<string>
         */
        public array $images,
        /** Author name, or null. */
        public ?string $creator,
        /** Author page, or null. */
        public ?string $creatorUrl,
        /** Category, or null. */
        public ?string $category,
        /** License, or null when unknown. */
        public ?ModelLicense $license,
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
        public ModelStats $stats,
        /** ISO-8601 date as sent by the source (not validated), or null. */
        public ?string $publishedAt,
        /** ISO-8601 date as sent by the source (not validated), or null. */
        public ?string $updatedAt,
        /**
         * The same model on other sources.
         *
         * @var list<ModelRef>
         */
        public array $copies,
        /** Request id and daily quota of this response. */
        public ResponseMeta $meta,
    ) {}
}
