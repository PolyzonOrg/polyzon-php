<?php

declare(strict_types=1);

namespace Polyzon;

/**
 * Reference to a model: its source and id. Pass it to {@see Client::getModels()}.
 */
final readonly class ModelRef
{
    public function __construct(
        /** Source id, e.g. `thingiverse` (see {@see Source}). */
        public string $source,
        /** Model id within Polyzon, e.g. `tv-1234567`. */
        public string $id,
    ) {}
}
