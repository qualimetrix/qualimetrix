<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Profiler\Export;

use AiMessDetector\Core\Profiler\Span;

/**
 * Exports profiling data as JSON.
 *
 * The JSON format includes the complete span tree with timing and memory information.
 */
final class JsonExporter implements ProfileExporterInterface
{
    public function export(array $rootSpans): string
    {
        if ($rootSpans === []) {
            return json_encode([], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        }

        if (\count($rootSpans) === 1) {
            return json_encode($this->spanToArray($rootSpans[0]), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        }

        $data = array_map(fn(Span $span) => $this->spanToArray($span), $rootSpans);

        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
    }

    /**
     * Convert span to array representation.
     *
     * @return array{
     *     name: string,
     *     category: string|null,
     *     duration_ms: float|null,
     *     memory_delta_bytes: int|null,
     *     peak_memory_delta_bytes: int|null,
     *     children: list<array>
     * }
     */
    private function spanToArray(Span $span): array
    {
        return [
            'name' => $span->name,
            'category' => $span->category,
            'duration_ms' => $span->getDuration(),
            'memory_delta_bytes' => $span->getMemoryDelta(),
            'peak_memory_delta_bytes' => $span->getPeakMemoryDelta(),
            'children' => array_map(
                fn(Span $child) => $this->spanToArray($child),
                $span->children,
            ),
        ];
    }
}
