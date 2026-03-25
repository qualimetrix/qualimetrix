<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

/**
 * Data structure for inheritance depth calculation.
 *
 * Stores class information and parent reference.
 */
final readonly class InheritanceClassInfo
{
    public function __construct(
        public ?string $namespace,
        public string $className,
        public int $line,
        public ?string $parentFqn,
    ) {}
}
