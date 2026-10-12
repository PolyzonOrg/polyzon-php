<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * Base class of every exception the SDK throws for a failed request.
 *
 * Invalid arguments (a malformed model id, base URL or token) throw the standard
 * {@see \InvalidArgumentException} before any request instead.
 */
class PolyzonException extends \RuntimeException {}
