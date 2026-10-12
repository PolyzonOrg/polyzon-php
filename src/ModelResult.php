<?php

declare(strict_types=1);

namespace Polyzon;

use Polyzon\Exception\PolyzonException;

/**
 * Outcome of one reference in {@see Client::getModels()}: the model or the error.
 */
final readonly class ModelResult
{
    /**
     * @internal Instances are built by the client; the signature may change in minor versions.
     */
    public function __construct(
        /** The requested reference. */
        public ModelRef $ref,
        /** The model, when the request succeeded. */
        public ?Model $model,
        /** Why the request failed, when it did. */
        public ?PolyzonException $error,
    ) {}

    /** True when the model was fetched. */
    public function isOk(): bool
    {
        return $this->error === null;
    }
}
