<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency\Export;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;

/**
 * Exports dependency graphs to JSON format.
 *
 * Features:
 * - Aggregated edges (unique from->to pairs with all types collected)
 * - Node list with FQN and namespace
 * - Statistics (node/edge counts)
 * - Namespace filtering (include/exclude) via shared options
 */
final class JsonGraphExporter implements GraphExporterInterface
{
    /**
     * @param array<string>|null $includeNamespaces
     * @param array<string> $excludeNamespaces
     */
    public function __construct(
        private readonly ?array $includeNamespaces = null,
        private readonly array $excludeNamespaces = [],
    ) {}

    public function export(DependencyGraphInterface $graph): string
    {
        $classes = $this->filterClasses($graph->getAllClasses());

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
            $sourceKey = $dependency->source->toCanonical();
            $targetKey = $dependency->target->toCanonical();

            // Only include edges where both nodes are in filtered set
            if (!isset($classSet[$sourceKey]) || !isset($classSet[$targetKey])) {
                continue;
            }

            $edgeKey = $sourceKey . '|' . $targetKey;

            if (!isset($edgeMap[$edgeKey])) {
                $edgeMap[$edgeKey] = [
                    'from' => $dependency->source->toString(),
                    'to' => $dependency->target->toString(),
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

        usort($edges, static fn(array $a, array $b): int => ($a['from'] <=> $b['from']) ?: ($a['to'] <=> $b['to']));

        $result = [
            'meta' => [
                'version' => '1.0.0',
                'package' => 'aimd',
                'timestamp' => date('c'),
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

    public function getFormat(): string
    {
        return 'json';
    }

    public function getFileExtension(): string
    {
        return 'json';
    }

    /**
     * Filters classes based on include/exclude namespaces.
     *
     * @param array<\AiMessDetector\Core\Symbol\SymbolPath> $classes
     *
     * @return array<\AiMessDetector\Core\Symbol\SymbolPath>
     */
    private function filterClasses(array $classes): array
    {
        $filtered = [];

        foreach ($classes as $classPath) {
            if (!$this->shouldIncludeClass($classPath)) {
                continue;
            }

            $filtered[] = $classPath;
        }

        return $filtered;
    }

    private function shouldIncludeClass(\AiMessDetector\Core\Symbol\SymbolPath $classPath): bool
    {
        $namespace = $classPath->namespace ?? '';

        // Check exclude list first
        foreach ($this->excludeNamespaces as $excludeNs) {
            if ($this->namespaceMatches($namespace, $excludeNs)) {
                return false;
            }
        }

        // Check include list (if specified)
        if ($this->includeNamespaces !== null) {
            foreach ($this->includeNamespaces as $includeNs) {
                if ($this->namespaceMatches($namespace, $includeNs)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Checks if a class namespace matches a filter namespace.
     * Supports exact match and prefix match.
     */
    private function namespaceMatches(string $classNamespace, string $filterNamespace): bool
    {
        if ($classNamespace === $filterNamespace) {
            return true;
        }

        return str_starts_with($classNamespace, $filterNamespace . '\\');
    }
}
