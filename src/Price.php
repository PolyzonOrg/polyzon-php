<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * Price of a model.
 */
final readonly class Price
{
    /** The model is free. */
    public const FREE = 'free';

    /** The model is paid. */
    public const PAID = 'paid';

    /** Pay what you want. */
    public const OPEN = 'open';

    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** `free`, `paid` or `open` (see the constants); newer values may appear. */
        public string $type,
        /** Amount in {@see $currency}, or null when unknown. */
        public ?float $value,
        /** ISO 4217 currency code, or null. */
        public ?string $currency,
        /** Human-readable price, e.g. `Бесплатно` or `$4.99`. */
        public string $display,
    ) {}
}
