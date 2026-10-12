<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * Counters of a search item; null means the source does not report that counter.
 */
final readonly class ItemStats
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** Likes. */
        public ?int $likes,
        /** Comments. */
        public ?int $comments,
        /** Makes (prints that people posted). */
        public ?int $makes,
        /** Saves to collections; downloads when the source does not count collections. */
        public ?int $collects,
    ) {}
}
