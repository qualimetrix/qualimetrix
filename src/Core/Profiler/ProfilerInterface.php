<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Profiler;

/**
 * Interface for profiling performance metrics.
 *
 * Profiler tracks execution time and memory usage across the application
 * using a tree structure of spans.
 */
interface ProfilerInterface
{
    /**
     * Start a new profiling span.
     *
     * Creates a new span and pushes it to the stack. If there's already an active
     * span, the new span becomes its child.
     *
     * @param string $name Span name (e.g., "FileProcessor::process")
     * @param string|null $category Optional category (e.g., "collection", "analysis")
     */
    public function start(string $name, ?string $category = null): void;

    /**
     * Stop the most recent profiling span with the given name.
     *
     * Pops the span from the stack and records end time/memory.
     * If no matching span is found in the stack, this is a no-op.
     *
     * @param string $name Span name to stop
     */
    public function stop(string $name): void;

    /**
     * Record a memory checkpoint for all active spans.
     *
     * Updates the peak memory of every span currently on the stack
     * with the current memory_get_usage(true). Cost: ~100ns per call.
     * Useful inside long operations that don't have their own sub-spans.
     */
    public function snapshot(): void;

    /**
     * Check if profiling is enabled.
     *
     * @return bool True if profiling is active
     */
    public function isEnabled(): bool;

    /**
     * Get the root span of the profiling tree.
     *
     * @return Span|null Root span, or null if no spans have been recorded
     */
    public function getRootSpan(): ?Span;

    /**
     * Get aggregated summary statistics.
     *
     * Returns statistics grouped by span name, including:
     * - total: Total time spent in milliseconds
     * - count: Number of times the span was executed
     * - avg: Average time per execution in milliseconds
     * - memory: Total memory delta in bytes
     * - peak_memory: Maximum peak memory delta across all instances (bytes above span start)
     *
     * @return array<string, array{total: float, count: int, avg: float, memory: int, peak_memory: int}>
     */
    public function getSummary(): array;

    /**
     * Export profiling data in the specified format.
     *
     * @param 'json'|'chrome-tracing' $format Export format
     *
     * @return string Formatted profiling data
     */
    public function export(string $format): string;

    /**
     * Clear all profiling data.
     *
     * Resets the profiler to initial state, removing all spans.
     * Useful for cleanup after analysis or in tests.
     */
    public function clear(): void;
}
