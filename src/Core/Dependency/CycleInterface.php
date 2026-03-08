<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Dependency;

use AiMessDetector\Core\Violation\SymbolPath;

/**
 * Interface for circular dependency cycles.
 *
 * Represents a cycle in the dependency graph where classes depend on each other
 * directly or transitively.
 */
interface CycleInterface
{
    /**
     * Returns all classes involved in the cycle.
     *
     * @return list<SymbolPath> Class-level SymbolPaths
     */
    public function getClasses(): array;

    /**
     * Returns the path forming the cycle.
     *
     * @return list<SymbolPath> Path with start class at both ends (e.g., [A, B, C, A])
     */
    public function getPath(): array;

    /**
     * Returns the number of classes in the cycle.
     */
    public function getSize(): int;

    /**
     * Returns human-readable representation with full class names.
     */
    public function toString(): string;

    /**
     * Returns short representation with only class names (without namespaces).
     */
    public function toShortString(): string;
}
