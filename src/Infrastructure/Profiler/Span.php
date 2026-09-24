<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler;

/**
 * One measured operation. Times are `hrtime()` nanoseconds; memory is
 * `memory_get_usage(true)` at the span's two boundaries, so the delta moves
 * in the allocator's blocks and says nothing about a peak between them.
 */
final class Span
{
    /**
     * False while running and for a span that ended only because an
     * enclosing span stopped: its end is when that happened, not when its
     * own work did, and a reader must not take its duration as measured.
     * A span constructed with its end is a stopped one.
     */
    private bool $stopped;

    public function __construct(
        public readonly string $name,
        public readonly ?string $category,
        public readonly float $startTime,
        public readonly int $startMemory,
        public ?float $endTime = null,
        public ?int $endMemory = null,
        public ?Span $parent = null,
    ) {
        $this->stopped = $endTime !== null;
    }

    /** @var list<Span> */
    public array $children = [];

    /** Ends the span at its own `stop()`. */
    public function finish(float $endTime, int $endMemory): void
    {
        $this->endTime = $endTime;
        $this->endMemory = $endMemory;
        $this->stopped = true;
    }

    /** Ends a span still open when an enclosing span stopped. */
    public function closeWithAncestor(float $endTime, int $endMemory): void
    {
        $this->endTime = $endTime;
        $this->endMemory = $endMemory;
    }

    public function attachTo(Span $parent): void
    {
        $this->parent = $parent;
        $parent->children[] = $this;
    }

    public function getDuration(): ?float
    {
        return $this->isRunning() ? null : ($this->endTime - $this->startTime) / 1_000_000;
    }

    public function getMemoryDelta(): ?int
    {
        return $this->isRunning() ? null : $this->endMemory - $this->startMemory;
    }

    /**
     * The one completion predicate; both ends are written together.
     *
     * @phpstan-assert-if-false !null $this->endTime
     * @phpstan-assert-if-false !null $this->endMemory
     */
    public function isRunning(): bool
    {
        return $this->endTime === null || $this->endMemory === null;
    }

    public function wasStopped(): bool
    {
        return $this->stopped;
    }
}
