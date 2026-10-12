<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * The account that owns the token.
 */
final readonly class Account
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** Account id. */
        public string $id,
        /** Display name, or null. */
        public ?string $displayName,
        /** ISO-8601 date. */
        public string $createdAt,
    ) {}
}
