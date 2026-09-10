<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Exports dependency graphs to DOT format (Graphviz).
 *
 * Features:
 * - Grouping by namespace (subgraph clusters)
 * - Short labels (class name only, not FQN)
 * - Color by instability (green=stable, red=unstable)
 * - Namespace filtering (include/exclude) via the shared {@see NamespaceFilter}
 * - Proper escaping of special characters
 */
final class DotExporter
{
    public function __construct(
        private readonly DotExporterOptions $options = new DotExporterOptions(),
    ) {}

    public function export(DependencyGraphInterface $graph): string
    {
        $classes = $this->namespaceFilter()->apply($graph->getAllClasses());

        if ($classes === []) {
            return $this->exportEmpty();
        }

        $lines = [];
        $lines[] = 'digraph Dependencies {';
        $lines[] = '    rankdir=' . $this->options->direction->value . ';';
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
     * @param array<SymbolPath> $classes
     *
     * @return array<string>
     */
    private function exportFlat(DependencyGraphInterface $graph, array $classes): array
    {
        $lines = [];
        $classSet = [];
        foreach ($classes as $classPath) {
            $classSet[$classPath->toCanonical()] = true;
        }

        // Nodes
        $lines[] = '    // Nodes';
        foreach ($classes as $classPath) {
            $fqcn = $classPath->toString();
            $label = $this->getLabel($fqcn);
            $color = $this->getNodeColor($classPath, $graph);
            $lines[] = \sprintf(
                '    "%s" [label="%s", fillcolor="%s"];',
                $this->escape($fqcn),
                $this->escape($label),
                $color,
            );
        }

        $lines[] = '';

        $this->appendEdges($lines, $graph, $classSet);

        return $lines;
    }

    /**
     * @param array<SymbolPath> $classes
     *
     * @return array<string>
     */
    private function exportWithClusters(DependencyGraphInterface $graph, array $classes): array
    {
        $lines = [];
        $byNamespace = $this->groupByNamespace($classes);
        $classSet = [];
        foreach ($classes as $classPath) {
            $classSet[$classPath->toCanonical()] = true;
        }

        // Subgraphs for each namespace
        $clusterIndex = 0;
        foreach ($byNamespace as $namespace => $namespaceClasses) {
            $lines[] = \sprintf('    subgraph cluster_%d {', $clusterIndex++);
            $lines[] = \sprintf('        label="%s";', $this->escape($namespace !== '' ? $namespace : 'Global'));
            $lines[] = '        style=filled;';
            $lines[] = '        fillcolor=lightyellow;';
            $lines[] = '';

            foreach ($namespaceClasses as $classPath) {
                $fqcn = $classPath->toString();
                $label = $this->getLabel($fqcn);
                $color = $this->getNodeColor($classPath, $graph);
                $lines[] = \sprintf(
                    '        "%s" [label="%s", fillcolor="%s"];',
                    $this->escape($fqcn),
                    $this->escape($label),
                    $color,
                );
            }

            $lines[] = '    }';
            $lines[] = '';
        }

        $this->appendEdges($lines, $graph, $classSet);

        return $lines;
    }

    /**
     * @param array<string> $lines
     * @param array<string, true> $classSet
     */
    private function appendEdges(array &$lines, DependencyGraphInterface $graph, array $classSet): void
    {
        $lines[] = '    // Edges';

        foreach ($graph->getAllDependencies() as $dependency) {
            // Only include edges where both nodes are in filtered set
            if (!isset($classSet[$dependency->sourceLogical()->toCanonical()]) || !isset($classSet[$dependency->targetLogical()->toCanonical()])) {
                continue;
            }

            $lines[] = \sprintf(
                '    "%s" -> "%s";',
                $this->escape($dependency->sourceLogical()->toString()),
                $this->escape($dependency->targetLogical()->toString()),
            );
        }
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

    private function getNodeColor(SymbolPath $class, DependencyGraphInterface $graph): string
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
     * @param array<SymbolPath> $classes
     *
     * @return array<string, array<SymbolPath>>
     */
    private function groupByNamespace(array $classes): array
    {
        $grouped = [];

        foreach ($classes as $classPath) {
            $namespace = $classPath->namespace ?? '';
            $grouped[$namespace][] = $classPath;
        }

        ksort($grouped);

        return $grouped;
    }

    private function namespaceFilter(): NamespaceFilter
    {
        return new NamespaceFilter($this->options->includeNamespaces, $this->options->excludeNamespaces);
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

}
