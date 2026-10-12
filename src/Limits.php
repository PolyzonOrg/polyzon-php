<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * Quota windows of the account (they are per account, not per token).
 */
final readonly class Limits
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** Requests per minute; every method counts. */
        public QuotaWindow $perMinute,
        /** Requests per UTC day; only search and model requests count. */
        public QuotaWindow $daily,
        /** Request id of this response. */
        public ResponseMeta $meta,
    ) {}
}
