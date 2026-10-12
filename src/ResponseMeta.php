<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * Metadata of a successful response.
 */
final readonly class ResponseMeta
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** `X-Request-ID` of the response — quote it when contacting support. Null when absent. */
        public ?string $requestId,
        /** The daily quota after this request, or null when the headers are absent. */
        public ?RateLimit $rateLimit,
    ) {}
}
