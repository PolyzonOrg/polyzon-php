<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * 403 `account_action_required`: the token owner must accept the current terms at https://polyzon.org/account/.
 */
final class PermissionDeniedException extends ApiException {}
