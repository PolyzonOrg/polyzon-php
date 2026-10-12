<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * License ids the catalog knows today. New ones may appear, so every method accepts and
 * returns any string; these constants are a convenience.
 */
final class License
{
    /** Public domain (CC0). */
    public const CC0 = 'cc0';

    /** Creative Commons Attribution. */
    public const CC_BY = 'cc-by';

    /** Creative Commons Attribution-ShareAlike. */
    public const CC_BY_SA = 'cc-by-sa';

    /** Creative Commons Attribution-NoDerivatives. */
    public const CC_BY_ND = 'cc-by-nd';

    /** Creative Commons Attribution-NonCommercial. */
    public const CC_BY_NC = 'cc-by-nc';

    /** Creative Commons Attribution-NonCommercial-ShareAlike. */
    public const CC_BY_NC_SA = 'cc-by-nc-sa';

    /** Creative Commons Attribution-NonCommercial-NoDerivatives. */
    public const CC_BY_NC_ND = 'cc-by-nc-nd';

    /** GNU GPL. */
    public const GPL = 'gpl';

    /** GNU LGPL. */
    public const LGPL = 'lgpl';

    /** MIT. */
    public const MIT = 'mit';

    /** BSD. */
    public const BSD = 'bsd';

    /** Private use only. */
    public const PRIVATE_USE = 'private-use';

    /** Commercial license. */
    public const COMMERCIAL = 'commercial';

    /** A custom license of the author. */
    public const CUSTOM = 'custom';

    /** Unknown license. */
    public const UNKNOWN = 'unknown';

    /** Every known value. */
    public const ALL = [
        self::CC0,
        self::CC_BY,
        self::CC_BY_SA,
        self::CC_BY_ND,
        self::CC_BY_NC,
        self::CC_BY_NC_SA,
        self::CC_BY_NC_ND,
        self::GPL,
        self::LGPL,
        self::MIT,
        self::BSD,
        self::PRIVATE_USE,
        self::COMMERCIAL,
        self::CUSTOM,
        self::UNKNOWN,
    ];

    private function __construct() {}
}
