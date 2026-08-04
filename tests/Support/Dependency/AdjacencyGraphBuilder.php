<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Support\Dependency;

use Qualimetrix\Analysis\Collection\Dependency\DependencyGraph;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;

/**
 * Builds a class-level dependency graph from a plain adjacency list.
 *
 * Insertion order is preserved verbatim — both the order in which classes first
 * appear and the order of each node's outgoing edges — which is what makes the
 * builder usable for order-sensitivity tests such as
 * {@see \Qualimetrix\Tests\Unit\Analysis\Collection\Dependency\CycleIdentityStabilityTest}.
 *
 * Only the fields the graph algorithms read are populated; the coupling counters
 * are left empty.
 */
final readonly class AdjacencyGraphBuilder
{
    /**
     * @param array<string, list<string>> $adjacencyList Class FQN → the FQNs it depends on
     */
    public static function build(array $adjacencyList): DependencyGraph
    {
        $dependencies = [];
        $bySource = [];
        $byTarget = [];
        /** @var array<string, SymbolPath> $classMap */
        $classMap = [];

        foreach ($adjacencyList as $source => $targets) {
            $sourcePath = SymbolPath::fromClassFqn($source);
            $sourceKey = $sourcePath->toCanonical();
            $classMap[$sourceKey] = $sourcePath;

            foreach ($targets as $target) {
                $targetPath = SymbolPath::fromClassFqn($target);
                $targetKey = $targetPath->toCanonical();
                $classMap[$targetKey] = $targetPath;

                $dependency = new Dependency(
                    source: $sourcePath,
                    target: $targetPath,
                    type: DependencyType::TypeHint,
                    location: new Location(RelativePath::fromString('test.php'), 1),
                );

                $dependencies[] = $dependency;
                $bySource[$sourceKey][] = $dependency;
                $byTarget[$targetKey][] = $dependency;
            }
        }

        return new DependencyGraph(
            dependencies: $dependencies,
            bySource: $bySource,
            byTarget: $byTarget,
            classes: array_values($classMap),
            namespaces: [],
            namespaceCe: [],
            namespaceCa: [],
            classCe: [],
            classCa: [],
        );
    }
}
