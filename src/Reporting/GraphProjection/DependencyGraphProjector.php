<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphProjectionRequest;

/**
 * Selects the internal graph representation requested by a delivery adapter.
 */
final class DependencyGraphProjector implements DependencyGraphProjectionInterface
{
    public function project(DependencyGraphInterface $graph, GraphProjectionRequest $request): string
    {
        return match ($request->format) {
            'dot' => (new DotExporter(new DotExporterOptions(
                direction: $request->direction,
                groupByNamespace: $request->groupByNamespace,
                includeNamespaces: $request->includeNamespaces,
                excludeNamespaces: $request->excludeNamespaces,
            )))->export($graph),
            'json' => (new JsonGraphExporter(
                includeNamespaces: $request->includeNamespaces,
                excludeNamespaces: $request->excludeNamespaces,
            ))->export($graph),
            default => throw new InvalidArgumentException(\sprintf(
                'Unsupported format: %s. Supported formats: dot, json',
                $request->format,
            )),
        };
    }
}
