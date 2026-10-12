<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * The token a request was made with (never the secret itself).
 */
final readonly class TokenInfo
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** Token id. */
        public string $id,
        /** Name given to the token in the account. */
        public string $name,
        /** First characters of the token, e.g. `pzk_Q7xR2mVb`. */
        public string $prefix,
        /** ISO-8601 date. */
        public string $createdAt,
    ) {}
}
