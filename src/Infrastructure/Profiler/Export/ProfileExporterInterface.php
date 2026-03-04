<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Profiler\Export;

use AiMessDetector\Core\Profiler\Span;

/**
 * Interface for exporting profiling data in various formats.
 */
interface ProfileExporterInterface
{
    /**
     * Export profiling data for the given root span.
     *
     * @param Span|null $rootSpan Root span of the profiling tree
     *
     * @return string Formatted profiling data
     */
    public function export(?Span $rootSpan): string;
}
