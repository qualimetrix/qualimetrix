<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\CycleInterface;
use AiMessDetector\Core\Symbol\SymbolPath;

/**
 * Represents a circular dependency in the dependency graph.
 *
 * A cycle is a path through the dependency graph where a class eventually
 * depends on itself through one or more intermediary dependencies.
 */
final readonly class Cycle implements CycleInterface
{
    /**
     * @param list<SymbolPath> $classes All classes involved in the cycle
     * @param list<SymbolPath> $path The actual path forming the cycle (includes start class at both ends)
     */
    public function __construct(
        private array $classes,
        private array $path,
    ) {}

    /**
     * @return list<SymbolPath>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }

    /**
     * @return list<SymbolPath>
     */
    public function getPath(): array
    {
        return $this->path;
    }

    public function getSize(): int
    {
        return \count($this->classes);
    }

    public function toString(): string
    {
        return implode(' → ', array_map(
            static fn(SymbolPath $p): string => $p->toString(),
            $this->path,
        ));
    }

    public function toShortString(): string
    {
        return implode(' → ', array_map(
            static fn(SymbolPath $p): string => $p->type ?? $p->toString(),
            $this->path,
        ));
    }
}
