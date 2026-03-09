<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Profiler\Export;

use AiMessDetector\Core\Profiler\Span;

/**
 * Exports profiling data in Chrome Tracing format.
 *
 * The output can be viewed in chrome://tracing for visualization.
 *
 * @see https://docs.google.com/document/d/1CvAClvFfyA5R-PhYUmn5OOQtYMH4h6I0nSsKchNAySU/preview
 */
final class ChromeTracingExporter implements ProfileExporterInterface
{
    public function export(array $rootSpans): string
    {
        if ($rootSpans === []) {
            return json_encode(['traceEvents' => []], \JSON_THROW_ON_ERROR);
        }

        $events = [];
        foreach ($rootSpans as $root) {
            $this->collectEvents($root, $events);
        }

        return json_encode(['traceEvents' => $events], \JSON_THROW_ON_ERROR);
    }

    /**
     * Recursively collect trace events from span tree.
     *
     * @param Span $span Current span
     * @param list<array{name: string, ph: string, ts: float, pid: int, tid: int, cat?: string}> &$events Events array
     */
    private function collectEvents(Span $span, array &$events): void
    {
        // Begin event
        $beginEvent = [
            'name' => $span->name,
            'ph' => 'B', // Begin
            'ts' => $span->startTime / 1000, // Convert ns to microseconds
            'pid' => 1, // Process ID
            'tid' => 1, // Thread ID
        ];

        if ($span->category !== null) {
            $beginEvent['cat'] = $span->category;
        }

        $events[] = $beginEvent;

        // Recursively process children
        foreach ($span->children as $child) {
            $this->collectEvents($child, $events);
        }

        // End event
        if ($span->endTime !== null) {
            $endEvent = [
                'name' => $span->name,
                'ph' => 'E', // End
                'ts' => $span->endTime / 1000, // Convert ns to microseconds
                'pid' => 1,
                'tid' => 1,
            ];

            if ($span->category !== null) {
                $endEvent['cat'] = $span->category;
            }

            $events[] = $endEvent;
        }
    }
}
