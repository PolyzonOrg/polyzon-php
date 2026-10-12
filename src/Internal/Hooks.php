<?php

declare(strict_types=1);

namespace Polyzon\Internal;

/**
 * Test seam: the retry wait and the jitter source. Not part of the public API.
 *
 * @internal
 */
final class Hooks
{
    /**
     * Maps a retry delay (seconds) to the seconds actually waited; null waits the delay.
     *
     * @var (\Closure(float): float)|null
     */
    public static ?\Closure $wait = null;

    /**
     * Returns the jitter in [0, 1]; null uses mt_rand().
     *
     * @var (\Closure(): float)|null
     */
    public static ?\Closure $random = null;
}
