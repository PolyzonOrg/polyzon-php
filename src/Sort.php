<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * Sort orders of {@see Client::search()}. Plain strings: any other value is passed through.
 */
final class Sort
{
    /** By relevance (the server default). */
    public const RELEVANT = 'relevant';

    /** By popularity. */
    public const POPULAR = 'popular';

    /** Newest first. */
    public const NEWEST = 'newest';

    /** Most liked first. */
    public const LIKES = 'likes';

    /** Most made first. */
    public const MAKES = 'makes';

    /** Most downloaded first. */
    public const DOWNLOADS = 'downloads';

    /** Every known value. */
    public const ALL = [self::RELEVANT, self::POPULAR, self::NEWEST, self::LIKES, self::MAKES, self::DOWNLOADS];

    private function __construct() {}
}
