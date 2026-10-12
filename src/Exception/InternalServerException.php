<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * 5xx: the server or the catalog failed (`unavailable`, `internal`, edge errors). Retried automatically.
 */
final class InternalServerException extends ApiException {}
