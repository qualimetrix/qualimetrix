<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

/**
 * Describes the source location where a dependency occurs.
 */
interface DependencyLocationInterface
{
    public function toString(): string;
}
