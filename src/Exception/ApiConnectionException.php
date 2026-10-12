<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * The request did not get an HTTP answer: DNS, TCP, TLS or proxy failure, connection reset.
 */
class ApiConnectionException extends PolyzonException {}
