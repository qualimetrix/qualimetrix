<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphExportFormat;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphProjectionRequest;

/**
 * Selects the internal graph representation requested by a delivery adapter.
 */
final class DependencyGraphProjector implements DependencyGraphProjectionInterface
{
    public function project(DependencyGraphInterface $graph, GraphProjectionRequest $request): string
    {
        // Exhaustive over `GraphExportFormat`'s two cases: the `default`
        // arm this `match` used to need is unreachable now that `$format`
        // is typed by the enum, not a bare string — an unrecognised
        // `--format` is refused by the command before a request is ever
        // built (`01-refusal-verdicts.md` §5.1).
        return match ($request->format) {
            GraphExportFormat::Dot => (new DotExporter(new DotExporterOptions(
                direction: $request->direction,
                groupByNamespace: $request->groupByNamespace,
                includeNamespaces: $request->includeNamespaces,
                excludeNamespaces: $request->excludeNamespaces,
            )))->export($graph),
            GraphExportFormat::Json => (new JsonGraphExporter(
                includeNamespaces: $request->includeNamespaces,
                excludeNamespaces: $request->excludeNamespaces,
            ))->export($graph),
        };
    }
}
