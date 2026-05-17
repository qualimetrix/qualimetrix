<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Profiler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Profiler\Span;

final class SpanTest extends TestCase
{
    #[Test]
    public function itReturnsNullDurationForRunningSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        self::assertNull($span->getDuration());
    }

    #[Test]
    public function itReturnsDurationInMilliseconds(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
            endTime: 2000000.0,
        );

        // 1000000 ns = 1 ms
        self::assertSame(1.0, $span->getDuration());
    }

    #[Test]
    public function itReturnsNullMemoryDeltaForRunningSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        self::assertNull($span->getMemoryDelta());
    }

    #[Test]
    public function itReturnsMemoryDeltaInBytes(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
            endTime: 2000000.0,
            endMemory: 250,
        );

        self::assertSame(150, $span->getMemoryDelta());
    }

    #[Test]
    public function itIsTrueWhenRunning(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        self::assertTrue($span->isRunning());
    }

    #[Test]
    public function itIsFalseWhenCompleted(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
            endTime: 2000000.0,
            endMemory: 150,
        );

        self::assertFalse($span->isRunning());
    }

    #[Test]
    public function itMaintainsParentChildRelationship(): void
    {
        $parent = new Span(
            name: 'parent',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        $child = new Span(
            name: 'child',
            category: 'category',
            startTime: 1500000.0,
            startMemory: 120,
            parent: $parent,
        );

        $parent->children[] = $child;

        self::assertSame($parent, $child->parent);
        self::assertCount(1, $parent->children);
        self::assertSame($child, $parent->children[0]);
    }

    #[Test]
    public function itAcceptsNullCategory(): void
    {
        $span = new Span(
            name: 'test',
            category: null,
            startTime: 1000000.0,
            startMemory: 100,
        );

        self::assertNull($span->category);
    }

    #[Test]
    public function itInitializesPeakMemoryToStartMemory(): void
    {
        $span = new Span(
            name: 'test',
            category: null,
            startTime: 1000000.0,
            startMemory: 1000,
        );

        self::assertSame(1000, $span->peakMemory);
    }

    #[Test]
    public function itUpdatesPeakWhenValueIsHigher(): void
    {
        $span = new Span(
            name: 'test',
            category: null,
            startTime: 1000000.0,
            startMemory: 1000,
        );

        $span->updatePeak(5000);
        self::assertSame(5000, $span->peakMemory);

        // Lower value should not update
        $span->updatePeak(3000);
        self::assertSame(5000, $span->peakMemory);
    }

    #[Test]
    public function itReturnsNullPeakMemoryDeltaForRunningSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: null,
            startTime: 1000000.0,
            startMemory: 1000,
        );

        $span->updatePeak(5000);
        self::assertNull($span->getPeakMemoryDelta());
    }

    #[Test]
    public function itReturnsPeakMemoryDeltaAboveStart(): void
    {
        $span = new Span(
            name: 'test',
            category: null,
            startTime: 1000000.0,
            startMemory: 1000,
            endTime: 2000000.0,
            endMemory: 2000,
        );

        $span->updatePeak(5000);
        self::assertSame(4000, $span->getPeakMemoryDelta());
    }

    #[Test]
    public function itReturnsPeakMemoryDeltaOfZeroWithNoPeakUpdate(): void
    {
        $span = new Span(
            name: 'test',
            category: null,
            startTime: 1000000.0,
            startMemory: 1000,
            endTime: 2000000.0,
            endMemory: 800,
        );

        // No updatePeak called — peak stays at startMemory
        self::assertSame(0, $span->getPeakMemoryDelta());
    }
}
