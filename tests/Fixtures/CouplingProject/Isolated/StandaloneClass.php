<?php

declare(strict_types=1);

namespace Fixtures\CouplingProject\Isolated;

/**
 * Completely isolated class with no dependencies.
 *
 * Expected metrics:
 * - Ca: 0 (nobody depends on this)
 * - Ce: 0 (no outgoing dependencies)
 * - Instability: 0.0 (isolated)
 */
class StandaloneClass
{
    public function process(): int
    {
        return 42;
    }
}
