<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * 429: too many requests. `rate_limited` (the per-minute window) is retried automatically;
 * the subclass {@see QuotaExceededException} means the daily quota is spent.
 */
class RateLimitException extends ApiException {}
