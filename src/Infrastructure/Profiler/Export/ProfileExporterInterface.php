<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler\Export;

use Qualimetrix\Core\Profiler\Span;

/**
 * Interface for exporting profiling data in various formats.
 */
interface ProfileExporterInterface
{
    /**
     * Export profiling data for the given root spans.
     *
     * @param list<Span> $rootSpans Root spans of the profiling tree
     *
     * @return string Formatted profiling data
     */
    public function export(array $rootSpans): string;
}
