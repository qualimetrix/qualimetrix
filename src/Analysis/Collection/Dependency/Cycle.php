<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency;

use Qualimetrix\Core\Dependency\CycleInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Represents a circular dependency in the dependency graph.
 *
 * A cycle is a path through the dependency graph where a class eventually
 * depends on itself through one or more intermediary dependencies.
 */
final readonly class Cycle implements CycleInterface
{
    /**
     * Display labels for the members of this cycle, built once over its whole
     * membership: the full and the truncated rendering then agree on every
     * member, and a namesake that the displayed path skips still counts as a
     * reason to disambiguate.
     *
     * Built eagerly, so a cycle the rule later drops by `maxCycleSize` pays for
     * labels nobody reads. That is deliberate: the cost is linear in the number
     * of members, the members of all cycles together cannot exceed the classes
     * in the graph (Tarjan's SCCs are disjoint), and this class is readonly, so
     * memoising on demand would mean giving that up for a rounding error.
     */
    private CycleMemberLabels $labels;

    /**
     * @param list<SymbolPath> $classes All classes involved in the cycle, sorted by canonical key;
     *                                  the first entry is the cycle representative
     * @param list<SymbolPath> $path The actual path forming the cycle (includes the representative at both ends)
     */
    public function __construct(
        private array $classes,
        private array $path,
    ) {
        // Both lists, not just $classes: the labels have to cover everything
        // that can be rendered ($path) as well as everything that can make a
        // name ambiguous ($classes). The detector only ever walks the path
        // through members of the component, but nothing here enforces that.
        $this->labels = CycleMemberLabels::forMembers([...$classes, ...$path]);
    }

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
            fn(SymbolPath $p): string => $this->labels->labelFor($p),
            $this->path,
        ));
    }

    /**
     * Returns a truncated short string showing at most $maxEntries classes from the path.
     *
     * For cycles larger than $maxEntries, shows the first classes plus "... (N more)".
     */
    public function toTruncatedShortString(int $maxEntries = 5): string
    {
        $pathCount = \count($this->path);

        // If the path fits within the limit (+1 for closing node), show full path
        if ($pathCount <= $maxEntries + 1) {
            return $this->toShortString();
        }

        $shown = \array_slice($this->path, 0, $maxEntries);
        $shownStrings = array_map(
            fn(SymbolPath $p): string => $this->labels->labelFor($p),
            $shown,
        );

        $remaining = $pathCount - $maxEntries - 1; // -1 for closing node (same as first)

        return implode(' → ', $shownStrings) . \sprintf(' → ... (%d more)', $remaining);
    }

    /**
     * Returns the cycle size category.
     *
     * @return 'small'|'medium'|'large'
     */
    public function getSizeCategory(): string
    {
        $size = $this->getSize();

        if ($size <= 5) {
            return 'small';
        }

        if ($size <= 20) {
            return 'medium';
        }

        return 'large';
    }

    /**
     * Returns the cycle as structured data for JSON consumers.
     *
     * Members are fully qualified: this output is meant to be processed, not
     * read, so the short names used by the display renderings — which are
     * ambiguous across namespaces — would make it useless.
     *
     * @return array{cycle: list<string>, length: int, category: string}
     */
    public function toStructuredData(): array
    {
        return [
            'cycle' => array_map(
                static fn(SymbolPath $p): string => $p->toString(),
                $this->path,
            ),
            'length' => $this->getSize(),
            'category' => $this->getSizeCategory(),
        ];
    }
}
