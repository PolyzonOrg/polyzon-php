<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * The daily quota as reported by the `x-ratelimit-*` response headers.
 */
final readonly class RateLimit
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** Requests allowed per UTC day. */
        public int $limit,
        /** Requests left today. */
        public int $remaining,
        /** Start of the next UTC day, Unix seconds. */
        public int $resetAt,
    ) {}
}
