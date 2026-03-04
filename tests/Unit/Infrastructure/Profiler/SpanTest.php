<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Profiler;

use AiMessDetector\Core\Profiler\Span;
use PHPUnit\Framework\TestCase;

final class SpanTest extends TestCase
{
    public function testGetDurationReturnsNullForRunningSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        self::assertNull($span->getDuration());
    }

    public function testGetDurationReturnsMilliseconds(): void
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

    public function testGetMemoryDeltaReturnsNullForRunningSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        self::assertNull($span->getMemoryDelta());
    }

    public function testGetMemoryDeltaReturnsBytes(): void
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

    public function testIsRunningReturnsTrueForRunningSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        self::assertTrue($span->isRunning());
    }

    public function testIsRunningReturnsFalseForCompletedSpan(): void
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

    public function testParentChildRelationship(): void
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

    public function testSpanWithoutCategory(): void
    {
        $span = new Span(
            name: 'test',
            category: null,
            startTime: 1000000.0,
            startMemory: 100,
        );

        self::assertNull($span->category);
    }
}
