<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * 400: an invalid parameter (`bad_request`, see {@see ApiException::$param}) or a page too deep (`page_too_deep`).
 */
final class BadRequestException extends ApiException {}
