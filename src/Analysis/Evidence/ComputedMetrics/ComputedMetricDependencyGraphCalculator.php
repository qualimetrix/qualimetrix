<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;

/**
 * Sorts computed-metric definitions into dependency order using Kahn's algorithm.
 *
 * The algorithm is split into four phases — graph construction, in-degree
 * counting, queue traversal, and cycle detection — each in its own method.
 * This keeps NPath/CCN/cognitive complexity within project thresholds while
 * preserving the exact evaluation order of the original single-method
 * implementation.
 */
final class ComputedMetricDependencyGraphCalculator
{
    public function __construct(
        private readonly ComputedMetricExpression $expression = new ComputedMetricExpression(),
    ) {}

    /**
     * Sorts definitions in dependency order.
     *
     * @param list<ComputedMetricDefinition> $definitions
     *
     * @return list<ComputedMetricDefinition>|null Dependency-ordered definitions,
     *                                             or null if a circular dependency was detected.
     */
    public function sort(array $definitions): ?array
    {
        $byName = $this->indexByName($definitions);
        $deps = $this->buildDependencyGraph($definitions, $byName);
        [$reverseDeps, $inDegree] = $this->buildReverseDepsAndInDegree($byName, $deps);
        $sortedNames = $this->traverseQueue($inDegree, $reverseDeps);

        if (\count($sortedNames) !== \count($definitions)) {
            return null;
        }

        return array_map(static fn(string $name): ComputedMetricDefinition => $byName[$name], $sortedNames);
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     *
     * @return array<string, ComputedMetricDefinition>
     */
    private function indexByName(array $definitions): array
    {
        $byName = [];
        foreach ($definitions as $def) {
            $byName[$def->name] = $def;
        }

        return $byName;
    }

    /**
     * Builds adjacency: deps[A] = [B, C] means A depends on B and C.
     *
     * @param list<ComputedMetricDefinition> $definitions
     * @param array<string, ComputedMetricDefinition> $byName
     *
     * @return array<string, list<string>>
     */
    private function buildDependencyGraph(array $definitions, array $byName): array
    {
        $deps = [];
        foreach ($definitions as $def) {
            $deps[$def->name] = $this->collectDependenciesOf($def, $byName);
        }

        return $deps;
    }

    /**
     * @param array<string, ComputedMetricDefinition> $byName
     *
     * @return list<string>
     */
    private function collectDependenciesOf(ComputedMetricDefinition $def, array $byName): array
    {
        $nodeDeps = [];
        foreach ($def->formulas as $formula) {
            foreach ($this->extractComputedMetricDeps($formula) as $depName) {
                if (isset($byName[$depName]) && $depName !== $def->name) {
                    $nodeDeps[] = $depName;
                }
            }
        }

        return array_values(array_unique($nodeDeps));
    }

    /**
     * Computes reverse edges and in-degree counts.
     *
     * reverseDeps[B] = [A] means "A depends on B, so after B is done, A can proceed".
     *
     * @param array<string, ComputedMetricDefinition> $byName
     * @param array<string, list<string>> $deps
     *
     * @return array{0: array<string, list<string>>, 1: array<string, int>}
     */
    private function buildReverseDepsAndInDegree(array $byName, array $deps): array
    {
        $reverseDeps = array_fill_keys(array_keys($byName), []);
        $inDegree = array_fill_keys(array_keys($byName), 0);

        foreach ($deps as $node => $nodeDeps) {
            $inDegree[$node] = \count($nodeDeps);
            foreach ($nodeDeps as $dep) {
                if (isset($reverseDeps[$dep])) {
                    $reverseDeps[$dep][] = $node;
                }
            }
        }

        return [$reverseDeps, $inDegree];
    }

    /**
     * Processes the ready queue (Kahn's algorithm), producing a dependency-ordered
     * list of definition names.
     *
     * @param array<string, int> $inDegree
     * @param array<string, list<string>> $reverseDeps
     *
     * @return list<string>
     */
    private function traverseQueue(array $inDegree, array $reverseDeps): array
    {
        $queue = $this->initialQueue($inDegree);

        $sorted = [];
        while ($queue !== []) {
            $node = array_shift($queue);
            $sorted[] = $node;

            foreach ($reverseDeps[$node] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        return $sorted;
    }

    /**
     * @param array<string, int> $inDegree
     *
     * @return list<string>
     */
    private function initialQueue(array $inDegree): array
    {
        $queue = [];
        foreach ($inDegree as $node => $degree) {
            if ($degree === 0) {
                $queue[] = $node;
            }
        }

        return $queue;
    }

    /**
     * Extract computed metric dependencies from a formula.
     * Keys under health.* or computed.* are inter-metric references.
     *
     * @return list<string>
     */
    private function extractComputedMetricDeps(string $formula): array
    {
        return $this->expression->computedReferencesOf($formula);
    }
}
