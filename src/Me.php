<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * The account and the token of the client.
 */
final readonly class Me
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** The account. */
        public Account $account,
        /** The token. */
        public TokenInfo $token,
        /** Request id of this response. */
        public ResponseMeta $meta,
    ) {}
}
