<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * 410 `gone`: the model was removed at the right holder's request. If you stored it, delete it too.
 */
final class GoneException extends ApiException {}
