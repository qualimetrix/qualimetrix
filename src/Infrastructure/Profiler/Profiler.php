<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler;

use InvalidArgumentException;
use Qualimetrix\Infrastructure\Profiler\Export\ChromeTracingExporter;
use Qualimetrix\Infrastructure\Profiler\Export\JsonExporter;
use Qualimetrix\Infrastructure\Profiler\Export\ProfileExporterInterface;

/**
 * Main profiler implementation using a tree-based approach.
 *
 * Uses a stack to track nested spans and builds a tree structure
 * representing the call hierarchy.
 */
final class Profiler
{
    /**
     * @var list<Span> Stack of active spans
     */
    private array $stack = [];

    /** @var list<Span> Top-level spans (multiple roots supported) */
    private array $rootSpans = [];

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
            $span->attachTo($parent);
        } else {
            // This is a root span
            $this->rootSpans[] = $span;
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

        // Enforce LIFO: close every span above the target first. They did not
        // stop themselves, and say so, rather than pass their borrowed end
        // off as a measurement.
        for ($i = \count($this->stack) - 1; $i > $index; $i--) {
            $this->stack[$i]->closeWithAncestor($now, $memory);
        }

        $this->stack[$index]->finish($now, $memory);

        // Remove the target span and all spans above it from the stack
        array_splice($this->stack, $index);
    }

    public function getRootSpan(): ?Span
    {
        return $this->rootSpans[0] ?? null;
    }

    /**
     * @return list<Span>
     */
    public function getRootSpans(): array
    {
        return $this->rootSpans;
    }

    /** @return array<string, array{total: float, count: int, unstopped: int}> */
    public function getSummary(): array
    {
        $stats = [];
        foreach ($this->rootSpans as $root) {
            $this->collectStats($root, $stats);
        }

        return $stats;
    }

    /**
     * Recursively collect statistics from span tree. A span that did not stop
     * itself is counted apart and its time left out of `total`: it would add
     * whatever ran until an enclosing span stopped.
     *
     * @param array<string, array{total: float, count: int, unstopped: int}> $stats
     */
    private function collectStats(Span $span, array &$stats): void
    {
        $stats[$span->name] ??= ['total' => 0.0, 'count' => 0, 'unstopped' => 0];

        $duration = $span->getDuration();
        if ($span->wasStopped() && $duration !== null) {
            $stats[$span->name]['total'] += $duration;
            $stats[$span->name]['count']++;
        } else {
            $stats[$span->name]['unstopped']++;
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

        return $this->exporters[$format]->export($this->rootSpans);
    }

    public function clear(): void
    {
        $this->stack = [];
        $this->rootSpans = [];
    }
}
