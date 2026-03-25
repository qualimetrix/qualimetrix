<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Dependency;

use Qualimetrix\Core\Symbol\SymbolPath;

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

    /**
     * Returns a truncated short string showing at most $maxEntries classes.
     *
     * For cycles larger than $maxEntries, shows the first classes plus "... (N more)".
     */
    public function toTruncatedShortString(int $maxEntries = 5): string;

    /**
     * Returns the cycle size category: 'small' (2-5), 'medium' (6-20), 'large' (21+).
     *
     * @return 'small'|'medium'|'large'
     */
    public function getSizeCategory(): string;

    /**
     * Returns the cycle as structured data for JSON consumers.
     *
     * @return array{cycle: list<string>, length: int, category: string}
     */
    public function toStructuredData(): array;
}
