<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler\Export;

use Qualimetrix\Infrastructure\Profiler\Span;

/**
 * Exports profiling data as JSON: `{"spans": [...]}`, one tree per root span.
 *
 * The top level is the same object whatever the run recorded; it used to be
 * `[]`, a single span object or a list depending on how many roots there
 * were. `stopped` is false for a span that ended only when an enclosing span
 * stopped, or never ended: its `duration_ms` is not a measurement of its own.
 */
final class JsonExporter implements ProfileExporterInterface
{
    public function export(array $rootSpans): string
    {
        return json_encode(
            ['spans' => array_map($this->spanToArray(...), $rootSpans)],
            \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array{
     *     name: string,
     *     category: string|null,
     *     duration_ms: float|null,
     *     memory_delta_bytes: int|null,
     *     stopped: bool,
     *     children: list<array<string, mixed>>
     * }
     */
    private function spanToArray(Span $span): array
    {
        return [
            'name' => $span->name,
            'category' => $span->category,
            'duration_ms' => $span->getDuration(),
            'memory_delta_bytes' => $span->getMemoryDelta(),
            'stopped' => $span->wasStopped(),
            'children' => array_map($this->spanToArray(...), $span->children),
        ];
    }
}
