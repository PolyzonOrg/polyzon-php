<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * License of a model.
 */
final readonly class ModelLicense
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** License id, see {@see License}; newer values may appear. */
        public string $id,
        /** License text as shown on the source, or null. */
        public ?string $raw,
    ) {}
}
