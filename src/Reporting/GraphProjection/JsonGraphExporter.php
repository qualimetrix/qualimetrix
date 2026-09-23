<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\ProductIdentity;

/**
 * Exports dependency graphs to JSON format.
 *
 * Features:
 * - Aggregated edges (unique from->to pairs with all types collected)
 * - Node list with FQN and namespace
 * - Statistics (node/edge counts)
 * - Namespace filtering (include/exclude) via the shared {@see NamespaceFilter}
 */
final class JsonGraphExporter
{
    /**
     * @param list<NamespacePattern>|null $includeNamespaces
     * @param list<NamespacePattern> $excludeNamespaces
     */
    public function __construct(
        private readonly ?array $includeNamespaces = null,
        private readonly array $excludeNamespaces = [],
    ) {}

    public function export(DependencyGraphInterface $graph): string
    {
        $classes = (new NamespaceFilter($this->includeNamespaces, $this->excludeNamespaces))->apply($graph->getAllClasses());

        $classSet = [];
        foreach ($classes as $classPath) {
            $classSet[$classPath->toCanonical()] = true;
        }

        // Build nodes
        $nodes = [];
        foreach ($classes as $classPath) {
            $nodes[] = [
                'fqn' => $classPath->toString(),
                'namespace' => $classPath->namespace ?? '',
            ];
        }

        usort($nodes, static fn(array $a, array $b): int => $a['fqn'] <=> $b['fqn']);

        // Build aggregated edges
        /** @var array<string, array{from: string, to: string, types: array<string, true>, count: int}> $edgeMap */
        $edgeMap = [];

        foreach ($graph->getAllDependencies() as $dependency) {
            $sourceKey = $dependency->sourceLogical()->toCanonical();
            $targetKey = $dependency->targetLogical()->toCanonical();

            // Only include edges where both nodes are in filtered set
            if (!isset($classSet[$sourceKey]) || !isset($classSet[$targetKey])) {
                continue;
            }

            $edgeKey = $sourceKey . '|' . $targetKey;

            if (!isset($edgeMap[$edgeKey])) {
                $edgeMap[$edgeKey] = [
                    'from' => $dependency->sourceLogical()->toString(),
                    'to' => $dependency->targetLogical()->toString(),
                    'types' => [],
                    'count' => 0,
                ];
            }

            $edgeMap[$edgeKey]['types'][$dependency->type->value] = true;
            $edgeMap[$edgeKey]['count']++;
        }

        // Convert edge map to sorted list
        $edges = [];
        foreach ($edgeMap as $edge) {
            $types = array_keys($edge['types']);
            sort($types);

            $edges[] = [
                'from' => $edge['from'],
                'to' => $edge['to'],
                'types' => $types,
                'count' => $edge['count'],
            ];
        }

        usort($edges, static fn(array $a, array $b): int => ($a['from'] <=> $b['from']) !== 0 ? ($a['from'] <=> $b['from']) : ($a['to'] <=> $b['to']));

        $identity = ProductIdentity::identity();

        $result = [
            // Not ProductIdentity::meta(): this envelope's `version` is the
            // graph format version and `package` is its own literal, not the
            // tool's identity — the same treatment MetricsJsonFormatter gives
            // its own `version`. Only the two keys this envelope does not
            // already carry come from ProductIdentity.
            'meta' => [
                'version' => '1.0.0',
                'package' => 'qmx',
                'timestamp' => gmdate('c'),
                'docs' => $identity['docs'],
                'llmsTxt' => $identity['llmsTxt'],
            ],
            'statistics' => [
                'nodeCount' => \count($nodes),
                'edgeCount' => \count($edges),
            ],
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        return json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }
}
