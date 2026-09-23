<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\PublishedUtf8;

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
        $repairs = 0;
        if ($this->options->groupByNamespace) {
            $lines = [...$lines, ...$this->exportWithClusters($graph, $classes, $repairs)];
        } else {
            $lines = [...$lines, ...$this->exportFlat($graph, $classes, $repairs)];
        }

        // A class or method name can carry a byte `nikic/php-parser` accepts
        // inside an identifier but that is not valid UTF-8 — see
        // {@see PublishedUtf8}. Unlike JSON, writing that byte here would not
        // fail: DOT has no encoding check, so the document would carry it
        // silently, with no diagnostic that anything was wrong.
        if ($repairs > 0) {
            $lines[] = '';
            $lines[] = '    // ' . PublishedUtf8::REPAIR_CHECK . ': ' . PublishedUtf8::describe($repairs);
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param array<SymbolPath> $classes
     *
     * @return array<string>
     */
    private function exportFlat(DependencyGraphInterface $graph, array $classes, int &$repairs): array
    {
        $lines = [];
        $classSet = [];
        foreach ($classes as $classPath) {
            $classSet[$classPath->toCanonical()] = true;
        }

        // Nodes
        $lines[] = '    // Nodes';
        foreach ($classes as $classPath) {
            $fqcn = PublishedUtf8::repair($classPath->toString(), $repairs);
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

        $this->appendEdges($lines, $graph, $classSet, $repairs);

        return $lines;
    }

    /**
     * @param array<SymbolPath> $classes
     *
     * @return array<string>
     */
    private function exportWithClusters(DependencyGraphInterface $graph, array $classes, int &$repairs): array
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
            $label = PublishedUtf8::repair($namespace !== '' ? $namespace : 'Global', $repairs);
            $lines[] = \sprintf('    subgraph cluster_%d {', $clusterIndex++);
            $lines[] = \sprintf('        label="%s";', $this->escape($label));
            $lines[] = '        style=filled;';
            $lines[] = '        fillcolor=lightyellow;';
            $lines[] = '';

            foreach ($namespaceClasses as $classPath) {
                $fqcn = PublishedUtf8::repair($classPath->toString(), $repairs);
                $classLabel = $this->getLabel($fqcn);
                $color = $this->getNodeColor($classPath, $graph);
                $lines[] = \sprintf(
                    '        "%s" [label="%s", fillcolor="%s"];',
                    $this->escape($fqcn),
                    $this->escape($classLabel),
                    $color,
                );
            }

            $lines[] = '    }';
            $lines[] = '';
        }

        $this->appendEdges($lines, $graph, $classSet, $repairs);

        return $lines;
    }

    /**
     * @param array<string> $lines
     * @param array<string, true> $classSet
     */
    private function appendEdges(array &$lines, DependencyGraphInterface $graph, array $classSet, int &$repairs): void
    {
        $lines[] = '    // Edges';

        foreach ($graph->getAllDependencies() as $dependency) {
            // Only include edges where both nodes are in filtered set
            if (!isset($classSet[$dependency->sourceLogical()->toCanonical()]) || !isset($classSet[$dependency->targetLogical()->toCanonical()])) {
                continue;
            }

            $lines[] = \sprintf(
                '    "%s" -> "%s";',
                $this->escape(PublishedUtf8::repair($dependency->sourceLogical()->toString(), $repairs)),
                $this->escape(PublishedUtf8::repair($dependency->targetLogical()->toString(), $repairs)),
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
