<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

use Qualimetrix\Core\Path\RelativePath;

/**
 * Describes the source location where a dependency occurs.
 */
interface DependencyLocationInterface
{
    public function file(): ?RelativePath;

    public function line(): ?int;

    public function toString(): string;
}
