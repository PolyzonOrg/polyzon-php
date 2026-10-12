<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * Languages of titles, descriptions and tags. Plain strings: any other value is passed through.
 */
final class Lang
{
    /** Russian (the server default). */
    public const RU = 'ru';

    /** English (machine-translated where needed). */
    public const EN = 'en';

    /** Every known value. */
    public const ALL = [self::RU, self::EN];

    private function __construct() {}
}
