<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * One quota window.
 */
final readonly class QuotaWindow
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** Requests allowed in the window. */
        public int $limit,
        /** Requests used. */
        public int $used,
        /** Requests left. */
        public int $remaining,
        /** Start of the next window, Unix seconds. */
        public int $resetAt,
    ) {}
}
