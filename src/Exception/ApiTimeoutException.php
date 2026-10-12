<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * A request attempt did not finish within the client's timeout.
 */
final class ApiTimeoutException extends ApiConnectionException {}
