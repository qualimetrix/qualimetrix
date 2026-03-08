<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Profiler;

use AiMessDetector\Core\Profiler\ProfilerInterface;
use AiMessDetector\Core\Profiler\Span;
use AiMessDetector\Infrastructure\Profiler\Export\ChromeTracingExporter;
use AiMessDetector\Infrastructure\Profiler\Export\JsonExporter;
use AiMessDetector\Infrastructure\Profiler\Export\ProfileExporterInterface;
use InvalidArgumentException;

/**
 * Main profiler implementation using a tree-based approach.
 *
 * Uses a stack to track nested spans and builds a tree structure
 * representing the call hierarchy.
 */
final class Profiler implements ProfilerInterface
{
    /**
     * @var list<Span> Stack of active spans
     */
    private array $stack = [];

    private ?Span $rootSpan = null;

    /**
     * @var array<string, ProfileExporterInterface>
     */
    private array $exporters;

    public function __construct()
    {
        $this->exporters = [
            'json' => new JsonExporter(),
            'chrome-tracing' => new ChromeTracingExporter(),
        ];
    }

    public function start(string $name, ?string $category = null): void
    {
        $span = new Span(
            name: $name,
            category: $category,
            startTime: hrtime(true),
            startMemory: memory_get_usage(true),
        );

        // If there's an active span, make this span its child
        if ($this->stack !== []) {
            $parent = $this->stack[array_key_last($this->stack)];
            $span->parent = $parent;
            $parent->children[] = $span;
        } else {
            // This is a root span
            $this->rootSpan = $span;
        }

        $this->stack[] = $span;
    }

    public function stop(string $name): void
    {
        // Find the most recent span with the given name in the stack
        $index = null;
        for ($i = \count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]->name === $name) {
                $index = $i;
                break;
            }
        }

        // No matching span found - this is a no-op
        if ($index === null) {
            return;
        }

        $now = hrtime(true);
        $memory = memory_get_usage(true);

        // Enforce LIFO: stop all spans above the target span first
        for ($i = \count($this->stack) - 1; $i > $index; $i--) {
            $aboveSpan = $this->stack[$i];
            if ($aboveSpan->endTime === null) {
                $aboveSpan->endTime = $now;
                $aboveSpan->endMemory = $memory;
            }
        }

        // Stop the target span
        $span = $this->stack[$index];
        $span->endTime = $now;
        $span->endMemory = $memory;

        // Remove the target span and all spans above it from the stack
        array_splice($this->stack, $index);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getRootSpan(): ?Span
    {
        return $this->rootSpan;
    }

    public function getSummary(): array
    {
        if ($this->rootSpan === null) {
            return [];
        }

        $stats = [];
        $this->collectStats($this->rootSpan, $stats);

        // Calculate averages
        foreach ($stats as $name => &$stat) {
            $stat['avg'] = $stat['count'] > 0 ? $stat['total'] / $stat['count'] : 0.0;
        }

        return $stats;
    }

    /**
     * Recursively collect statistics from span tree.
     *
     * @param Span $span Current span
     * @param array<string, array{total: float, count: int, avg: float, memory: int}> &$stats Statistics array
     */
    private function collectStats(Span $span, array &$stats): void
    {
        $duration = $span->getDuration();
        $memory = $span->getMemoryDelta();

        if ($duration !== null && $memory !== null) {
            if (!isset($stats[$span->name])) {
                $stats[$span->name] = [
                    'total' => 0.0,
                    'count' => 0,
                    'avg' => 0.0,
                    'memory' => 0,
                ];
            }

            $stats[$span->name]['total'] += $duration;
            $stats[$span->name]['count']++;
            $stats[$span->name]['memory'] += $memory;
        }

        foreach ($span->children as $child) {
            $this->collectStats($child, $stats);
        }
    }

    public function export(string $format): string
    {
        if (!isset($this->exporters[$format])) {
            throw new InvalidArgumentException("Unsupported export format: {$format}");
        }

        return $this->exporters[$format]->export($this->rootSpan);
    }

    public function clear(): void
    {
        $this->stack = [];
        $this->rootSpan = null;
    }
}
