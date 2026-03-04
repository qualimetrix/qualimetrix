<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency\Export;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;

/**
 * Exports dependency graphs to DOT format (Graphviz).
 *
 * Features:
 * - Grouping by namespace (subgraph clusters)
 * - Short labels (class name only, not FQN)
 * - Color by instability (green=stable, red=unstable)
 * - Namespace filtering (include/exclude)
 * - Proper escaping of special characters
 */
final class DotExporter implements GraphExporterInterface
{
    public function __construct(
        private readonly DotExporterOptions $options = new DotExporterOptions(),
    ) {}

    public function export(DependencyGraphInterface $graph): string
    {
        $classes = $this->filterClasses($graph->getAllClasses());

        if (empty($classes)) {
            return $this->exportEmpty();
        }

        $lines = [];
        $lines[] = 'digraph Dependencies {';
        $lines[] = '    rankdir=' . $this->options->direction . ';';
        $lines[] = '    node [shape=box, style=filled, fillcolor=lightblue, fontname="Arial"];';
        $lines[] = '    edge [color=gray];';
        $lines[] = '';

        // Export nodes and edges
        if ($this->options->groupByNamespace) {
            $lines = [...$lines, ...$this->exportWithClusters($graph, $classes)];
        } else {
            $lines = [...$lines, ...$this->exportFlat($graph, $classes)];
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param array<string> $classes
     *
     * @return array<string>
     */
    private function exportFlat(DependencyGraphInterface $graph, array $classes): array
    {
        $lines = [];
        $classSet = array_flip($classes);

        // Nodes
        $lines[] = '    // Nodes';
        foreach ($classes as $class) {
            $label = $this->getLabel($class);
            $color = $this->getNodeColor($class, $graph);
            $lines[] = \sprintf(
                '    "%s" [label="%s", fillcolor="%s"];',
                $this->escape($class),
                $this->escape($label),
                $color,
            );
        }

        $lines[] = '';

        // Edges
        $lines[] = '    // Edges';
        foreach ($graph->getAllDependencies() as $dependency) {
            // Only include edges where both nodes are in filtered set
            if (!isset($classSet[$dependency->sourceClass]) || !isset($classSet[$dependency->targetClass])) {
                continue;
            }

            $lines[] = \sprintf(
                '    "%s" -> "%s";',
                $this->escape($dependency->sourceClass),
                $this->escape($dependency->targetClass),
            );
        }

        return $lines;
    }

    /**
     * @param array<string> $classes
     *
     * @return array<string>
     */
    private function exportWithClusters(DependencyGraphInterface $graph, array $classes): array
    {
        $lines = [];
        $byNamespace = $this->groupByNamespace($classes);
        $classSet = array_flip($classes);

        // Subgraphs for each namespace
        $clusterIndex = 0;
        foreach ($byNamespace as $namespace => $namespaceClasses) {
            $lines[] = \sprintf('    subgraph cluster_%d {', $clusterIndex++);
            $lines[] = \sprintf('        label="%s";', $this->escape($namespace ?: 'Global'));
            $lines[] = '        style=filled;';
            $lines[] = '        fillcolor=lightyellow;';
            $lines[] = '';

            foreach ($namespaceClasses as $class) {
                $label = $this->getShortLabel($class);
                $color = $this->getNodeColor($class, $graph);
                $lines[] = \sprintf(
                    '        "%s" [label="%s", fillcolor="%s"];',
                    $this->escape($class),
                    $this->escape($label),
                    $color,
                );
            }

            $lines[] = '    }';
            $lines[] = '';
        }

        // Edges (outside clusters)
        $lines[] = '    // Edges';
        foreach ($graph->getAllDependencies() as $dependency) {
            // Only include edges where both nodes are in filtered set
            if (!isset($classSet[$dependency->sourceClass]) || !isset($classSet[$dependency->targetClass])) {
                continue;
            }

            $lines[] = \sprintf(
                '    "%s" -> "%s";',
                $this->escape($dependency->sourceClass),
                $this->escape($dependency->targetClass),
            );
        }

        return $lines;
    }

    private function getLabel(string $fqcn): string
    {
        if ($this->options->shortLabels) {
            return $this->getShortLabel($fqcn);
        }

        return $fqcn;
    }

    private function getShortLabel(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    private function getNodeColor(string $class, DependencyGraphInterface $graph): string
    {
        if (!$this->options->colorByInstability) {
            return 'lightblue';
        }

        $ce = $graph->getClassCe($class);
        $ca = $graph->getClassCa($class);
        $total = $ce + $ca;

        if ($total === 0) {
            return 'lightblue';
        }

        $instability = $ce / $total;

        // Green (stable) -> Yellow -> Red (unstable)
        if ($instability < 0.3) {
            return 'lightgreen';
        }

        if ($instability < 0.7) {
            return 'lightyellow';
        }

        return 'lightcoral';
    }

    /**
     * Groups classes by their namespace.
     *
     * @param array<string> $classes
     *
     * @return array<string, array<string>>
     */
    private function groupByNamespace(array $classes): array
    {
        $grouped = [];

        foreach ($classes as $class) {
            $namespace = $this->extractNamespace($class);
            $grouped[$namespace][] = $class;
        }

        ksort($grouped);

        return $grouped;
    }

    private function extractNamespace(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, 0, $pos) : '';
    }

    /**
     * Filters classes based on include/exclude namespaces.
     *
     * @param array<string> $classes
     *
     * @return array<string>
     */
    private function filterClasses(array $classes): array
    {
        $filtered = [];

        foreach ($classes as $class) {
            if (!$this->shouldIncludeClass($class)) {
                continue;
            }

            $filtered[] = $class;
        }

        return $filtered;
    }

    private function shouldIncludeClass(string $class): bool
    {
        $namespace = $this->extractNamespace($class);

        // Check exclude list first
        foreach ($this->options->excludeNamespaces as $excludeNs) {
            if ($this->namespaceMatches($namespace, $excludeNs)) {
                return false;
            }
        }

        // Check include list (if specified)
        if ($this->options->includeNamespaces !== null) {
            foreach ($this->options->includeNamespaces as $includeNs) {
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
     * Supports exact match and prefix match (e.g., 'App\Service' matches 'App\Service\User').
     */
    private function namespaceMatches(string $classNamespace, string $filterNamespace): bool
    {
        // Exact match
        if ($classNamespace === $filterNamespace) {
            return true;
        }

        // Prefix match (e.g., 'App\Service' matches 'App\Service\User')
        return str_starts_with($classNamespace, $filterNamespace . '\\');
    }

    /**
     * Escapes special characters for DOT format.
     */
    private function escape(string $value): string
    {
        // Escape backslashes first, then quotes
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function exportEmpty(): string
    {
        return "digraph Dependencies {\n    // No classes to display\n}";
    }

    public function getFormat(): string
    {
        return 'dot';
    }

    public function getFileExtension(): string
    {
        return 'dot';
    }
}
