<?php

declare(strict_types=1);

namespace Fixtures\CouplingProject\Core;

/**
 * Core entity interface.
 *
 * Expected metrics:
 * - Ca: 1 (AbstractEntity depends on this)
 * - Ce: 0 (no outgoing dependencies)
 */
interface EntityInterface
{
    public function getId(): int;
}
