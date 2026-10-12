<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * 401: no token (`unauthorized`), or the token is wrong or revoked (`invalid_token`).
 */
final class AuthenticationException extends ApiException {}
