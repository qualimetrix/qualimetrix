<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler;

final class Span
{
    public int $peakMemory;

    public function __construct(
        public readonly string $name,
        public readonly ?string $category,
        public readonly float $startTime,
        public readonly int $startMemory,
        public ?float $endTime = null,
        public ?int $endMemory = null,
        public ?Span $parent = null,
    ) {
        $this->peakMemory = $startMemory;
    }

    /** @var list<Span> */
    public array $children = [];

    public function finish(float $endTime, int $endMemory): void
    {
        $this->endTime = $endTime;
        $this->endMemory = $endMemory;
        $this->updatePeak($endMemory);
    }

    public function attachTo(Span $parent): void
    {
        $this->parent = $parent;
        $parent->children[] = $this;
    }

    public function getDuration(): ?float
    {
        return $this->endTime === null ? null : ($this->endTime - $this->startTime) / 1_000_000;
    }
    public function getMemoryDelta(): ?int
    {
        return $this->endMemory === null ? null : $this->endMemory - $this->startMemory;
    }
    public function getPeakMemoryDelta(): ?int
    {
        return $this->endTime === null ? null : $this->peakMemory - $this->startMemory;
    }
    public function updatePeak(int $currentMemory): void
    {
        if ($currentMemory > $this->peakMemory) {
            $this->peakMemory = $currentMemory;
        }
    }
    public function isRunning(): bool
    {
        return $this->endTime === null;
    }
}
