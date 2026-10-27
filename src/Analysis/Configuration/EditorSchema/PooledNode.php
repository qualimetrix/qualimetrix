<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\EditorSchema;

/**
 * A placeholder for a pooled subtree until the pool knows how often it is
 * used. It does not encode as JSON: a placeholder left unresolved fails the
 * encoding instead of reaching the file.
 */
final readonly class PooledNode
{
    public function __construct(
        public string $name,
        public ?string $description,
    ) {}
}
