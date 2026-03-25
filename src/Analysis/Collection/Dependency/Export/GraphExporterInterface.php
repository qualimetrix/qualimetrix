<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Export;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;

/**
 * Interface for exporting dependency graphs to various formats.
 *
 * Implementations can export to DOT (Graphviz), Mermaid, or other visualization formats.
 */
interface GraphExporterInterface
{
    /**
     * Exports the dependency graph to a string representation.
     *
     * @param DependencyGraphInterface $graph The dependency graph to export
     *
     * @return string The exported graph in the specific format
     */
    public function export(DependencyGraphInterface $graph): string;

    /**
     * Returns the format name (e.g., 'dot', 'mermaid').
     */
    public function getFormat(): string;

    /**
     * Returns the recommended file extension for this format (e.g., 'dot', 'mmd').
     */
    public function getFileExtension(): string;
}
