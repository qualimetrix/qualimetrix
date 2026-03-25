<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

/**
 * Represents a detected identical sub-expression finding.
 */
final readonly class IdenticalSubExpressionFinding
{
    public function __construct(
        public string $type,
        public int $line,
        public string $detail,
    ) {}
}
