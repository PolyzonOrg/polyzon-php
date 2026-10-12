<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * Model sources (sites) the catalog knows today. New sources appear over time, so every
 * method accepts and returns any string; these constants are a convenience.
 */
final class Source
{
    /** Thingiverse. */
    public const THINGIVERSE = 'thingiverse';

    /** Cults3D. */
    public const CULTS3D = 'cults3d';

    /** Printables. */
    public const PRINTABLES = 'printables';

    /** MakerWorld. */
    public const MAKERWORLD = 'makerworld';

    /** 3DToday. */
    public const THREE_D_TODAY = '3dtoday';

    /** Creality Cloud. */
    public const CREALITYCLOUD = 'crealitycloud';

    /** MyMiniFactory. */
    public const MYMINIFACTORY = 'myminifactory';

    /** Thangs. */
    public const THANGS = 'thangs';

    /** YouMagine. */
    public const YOUMAGINE = 'youmagine';

    /** GrabCAD. */
    public const GRABCAD = 'grabcad';

    /** Sketchfab. */
    public const SKETCHFAB = 'sketchfab';

    /** Threeding. */
    public const THREEDING = 'threeding';

    /** Nexprint. */
    public const NEXPRINT = 'nexprint';

    /** NASA 3D Resources. */
    public const NASA = 'nasa';

    /** NIH 3D. */
    public const NIH3D = 'nih3d';

    /** Smithsonian 3D. */
    public const SMITHSONIAN = 'smithsonian';

    /** Pinshape. */
    public const PINSHAPE = 'pinshape';

    /** CGTrader. */
    public const CGTRADER = 'cgtrader';

    /** Gambody. */
    public const GAMBODY = 'gambody';

    /** JewelModel. */
    public const JEWELMODEL = 'jewelmodel';

    /** MakerOnline. */
    public const MAKERONLINE = 'makeronline';

    /** 3DExport. */
    public const THREE_D_EXPORT = '3dexport';

    /** Every known value. */
    public const ALL = [
        self::THINGIVERSE,
        self::CULTS3D,
        self::PRINTABLES,
        self::MAKERWORLD,
        self::THREE_D_TODAY,
        self::CREALITYCLOUD,
        self::MYMINIFACTORY,
        self::THANGS,
        self::YOUMAGINE,
        self::GRABCAD,
        self::SKETCHFAB,
        self::THREEDING,
        self::NEXPRINT,
        self::NASA,
        self::NIH3D,
        self::SMITHSONIAN,
        self::PINSHAPE,
        self::CGTRADER,
        self::GAMBODY,
        self::JEWELMODEL,
        self::MAKERONLINE,
        self::THREE_D_EXPORT,
    ];

    private function __construct() {}
}
