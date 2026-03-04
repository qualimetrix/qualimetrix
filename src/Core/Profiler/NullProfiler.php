<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Profiler;

/**
 * No-op profiler implementation for production use.
 *
 * This profiler does nothing, providing minimal overhead when profiling is disabled.
 */
final class NullProfiler implements ProfilerInterface
{
    public function start(string $name, ?string $category = null): void
    {
        // No-op
    }

    public function stop(string $name): void
    {
        // No-op
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function getRootSpan(): ?Span
    {
        return null;
    }

    /**
     * @return array<string, array{total: float, count: int, avg: float, memory: int}>
     */
    public function getSummary(): array
    {
        return [];
    }

    public function export(string $format): string
    {
        return '';
    }

    public function clear(): void
    {
        // No-op
    }
}
