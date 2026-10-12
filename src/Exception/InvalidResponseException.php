<?php

declare(strict_types=1);

namespace Polyzon\Exception;

/**
 * The server answered 2xx, but the body is not a JSON object or exceeds the 8 MiB cap.
 */
final class InvalidResponseException extends PolyzonException
{
    /**
     * @param string $message What is wrong with the response.
     * @param int $status HTTP status of the response.
     * @param string|null $requestId `X-Request-ID` of the response, or null.
     * @param \Throwable|null $previous The underlying parse error, if any.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $requestId !== null ? \sprintf('%s (request id %s)', $message, ApiException::clean($requestId)) : $message,
            $status,
            $previous,
        );
    }
}
