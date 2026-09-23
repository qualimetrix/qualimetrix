<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Every namespace's boundary-crossing dependents and dependencies, in both scopes.
 *
 * A namespace answers the coupling question twice, and the two answers differ
 * wherever it both declares types and has sub-namespaces. The **subtree** scope
 * treats the namespace and everything under it as one region, so a dependency
 * between two of its children is internal. The **own** scope treats only the
 * declarations of exactly this namespace as the region, so the same dependency
 * crosses the boundary. Neither is derivable from the other, which is why both
 * are carried rather than one being recomputed on demand.
 *
 * The four numbers travel as one row per namespace because nothing reads a Ce
 * without meaning a scope: held apart, a caller can pair a subtree Ce with an
 * own Ca and get a ratio of two different regions, which nothing would report.
 * Counting happens once here, when the row is built, rather than on every
 * lookup.
 */
final readonly class NamespaceCouplings
{
    /** @param array<string, array{subtreeCe: int, subtreeCa: int, ownCe: int, ownCa: int}> $byNamespace */
    private function __construct(private array $byNamespace) {}

    /**
     * @param array<string, StringSet> $subtreeCe external classes the namespace's subtree depends on
     * @param array<string, StringSet> $subtreeCa external classes that depend on the namespace's subtree
     * @param array<string, StringSet> $ownCe same, for the declarations of exactly this namespace
     * @param array<string, StringSet> $ownCa same, for the declarations of exactly this namespace
     */
    public static function fromScopes(array $subtreeCe, array $subtreeCa, array $ownCe, array $ownCa): self
    {
        $rows = [];

        foreach ([$subtreeCe, $subtreeCa, $ownCe, $ownCa] as $sets) {
            foreach ($sets as $canonical => $set) {
                $rows[$canonical] ??= ['subtreeCe' => 0, 'subtreeCa' => 0, 'ownCe' => 0, 'ownCa' => 0];
            }
        }

        foreach ($rows as $canonical => $row) {
            $rows[$canonical] = [
                'subtreeCe' => isset($subtreeCe[$canonical]) ? $subtreeCe[$canonical]->count() : 0,
                'subtreeCa' => isset($subtreeCa[$canonical]) ? $subtreeCa[$canonical]->count() : 0,
                'ownCe' => isset($ownCe[$canonical]) ? $ownCe[$canonical]->count() : 0,
                'ownCa' => isset($ownCa[$canonical]) ? $ownCa[$canonical]->count() : 0,
            ];
        }

        return new self($rows);
    }

    /** An empty index: every namespace answers zero in both scopes. */
    public static function none(): self
    {
        return new self([]);
    }

    public function subtreeCe(SymbolPath $namespace): int
    {
        return $this->byNamespace[$namespace->toCanonical()]['subtreeCe'] ?? 0;
    }

    public function subtreeCa(SymbolPath $namespace): int
    {
        return $this->byNamespace[$namespace->toCanonical()]['subtreeCa'] ?? 0;
    }

    public function ownCe(SymbolPath $namespace): int
    {
        return $this->byNamespace[$namespace->toCanonical()]['ownCe'] ?? 0;
    }

    public function ownCa(SymbolPath $namespace): int
    {
        return $this->byNamespace[$namespace->toCanonical()]['ownCa'] ?? 0;
    }
}
