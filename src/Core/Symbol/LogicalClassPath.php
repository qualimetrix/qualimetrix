<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;

/**
 * Logical class graph identity, intentionally without source location.
 */
final readonly class LogicalClassPath
{
    public function __construct(public SymbolPath $symbolPath)
    {
        if ($symbolPath->getType() !== SymbolType::Class_) {
            throw new InvalidArgumentException('LogicalClassPath requires a class SymbolPath');
        }
    }

    public function toCanonical(): string
    {
        return $this->symbolPath->toCanonical();
    }
}
