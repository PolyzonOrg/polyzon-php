<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * 429 `quota_exceeded`: the daily quota is spent; it resets at 00:00 UTC. Never retried.
 */
final class QuotaExceededException extends RateLimitException {}
