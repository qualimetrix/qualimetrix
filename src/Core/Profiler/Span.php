<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Profiler;

/**
 * Mutable recording object representing a profiling span (time interval).
 *
 * Lifecycle: created via Profiler::start() with null end time,
 * then mutated by Profiler::stop() to record end time and memory.
 * Parent-child relationships form a tree representing the call hierarchy.
 */
final class Span
{
    /**
     * Highest memory_get_usage(true) observed during this span's lifetime.
     * Initialized to startMemory, updated on stop() and snapshot().
     * For parent spans, this includes peaks propagated from children.
     */
    public int $peakMemory;

    /**
     * @param string $name Span name (e.g., "FileProcessor::process")
     * @param string|null $category Optional category (e.g., "collection", "analysis")
     * @param float $startTime Start timestamp in nanoseconds (from hrtime(true))
     * @param int $startMemory Memory usage at start in bytes
     * @param float|null $endTime End timestamp in nanoseconds (null if still running)
     * @param int|null $endMemory Memory usage at end in bytes (null if still running)
     * @param Span|null $parent Parent span (null for root spans)
     * @param list<Span> $children Child spans
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $category,
        public readonly float $startTime,
        public readonly int $startMemory,
        public ?float $endTime = null,
        public ?int $endMemory = null,
        public ?Span $parent = null,
        public array $children = [],
    ) {
        $this->peakMemory = $startMemory;
    }

    /**
     * Get span duration in milliseconds.
     *
     * @return float|null Duration in milliseconds, or null if span is still running
     */
    public function getDuration(): ?float
    {
        if ($this->endTime === null) {
            return null;
        }

        return ($this->endTime - $this->startTime) / 1_000_000; // ns to ms
    }

    /**
     * Get memory delta in bytes.
     *
     * @return int|null Memory delta, or null if span is still running
     */
    public function getMemoryDelta(): ?int
    {
        if ($this->endMemory === null) {
            return null;
        }

        return $this->endMemory - $this->startMemory;
    }

    /**
     * Get peak memory above span start in bytes.
     *
     * @return int|null Peak memory delta, or null if span is still running
     */
    public function getPeakMemoryDelta(): ?int
    {
        if ($this->endTime === null) {
            return null;
        }

        return $this->peakMemory - $this->startMemory;
    }

    /**
     * Update peak memory if the given value is higher.
     */
    public function updatePeak(int $currentMemory): void
    {
        if ($currentMemory > $this->peakMemory) {
            $this->peakMemory = $currentMemory;
        }
    }

    /**
     * Check if span is still running.
     */
    public function isRunning(): bool
    {
        return $this->endTime === null;
    }
}
