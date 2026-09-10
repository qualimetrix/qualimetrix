<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

/**
 * Projects a dependency graph into a requested output representation.
 */
interface DependencyGraphProjectionInterface
{
    public function project(DependencyGraphInterface $graph, GraphProjectionRequest $request): string;

    /**
     * The request's include namespaces that bind to no class of this graph.
     *
     * Separate from {@see project()} so a delivery adapter can refuse a
     * namespace that names nothing instead of printing an empty graph the
     * caller cannot tell apart from a clean one. Both answers come from the
     * same comparison, so an exporter cannot render what this call reports as
     * unbound.
     *
     * @return list<string> in the order the request gave them; empty when
     *                      every include value bound, and when none was given
     */
    public function unboundIncludeNamespaces(DependencyGraphInterface $graph, GraphProjectionRequest $request): array;
}
