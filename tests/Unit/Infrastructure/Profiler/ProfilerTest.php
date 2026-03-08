<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Profiler;

use AiMessDetector\Infrastructure\Profiler\Profiler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProfilerTest extends TestCase
{
    private Profiler $profiler;

    protected function setUp(): void
    {
        $this->profiler = new Profiler();
    }

    public function testIsEnabledReturnsTrue(): void
    {
        self::assertTrue($this->profiler->isEnabled());
    }

    public function testGetRootSpanReturnsNullInitially(): void
    {
        self::assertNull($this->profiler->getRootSpan());
    }

    public function testStartCreatesRootSpan(): void
    {
        $this->profiler->start('test', 'category');

        $root = $this->profiler->getRootSpan();
        self::assertNotNull($root);
        self::assertSame('test', $root->name);
        self::assertSame('category', $root->category);
        self::assertTrue($root->isRunning());
    }

    public function testStopEndsSpan(): void
    {
        $this->profiler->start('test');
        $this->profiler->stop('test');

        $root = $this->profiler->getRootSpan();
        self::assertNotNull($root);
        self::assertFalse($root->isRunning());
    }

    public function testStopWithNonExistentNameIsNoOp(): void
    {
        $this->profiler->start('test');
        $this->profiler->stop('non-existent');

        $root = $this->profiler->getRootSpan();
        self::assertNotNull($root);
        self::assertTrue($root->isRunning());
    }

    public function testNestedSpansCreateTree(): void
    {
        $this->profiler->start('parent');
        $this->profiler->start('child1');
        $this->profiler->stop('child1');
        $this->profiler->start('child2');
        $this->profiler->stop('child2');
        $this->profiler->stop('parent');

        $root = $this->profiler->getRootSpan();
        self::assertNotNull($root);
        self::assertSame('parent', $root->name);
        self::assertCount(2, $root->children);
        self::assertSame('child1', $root->children[0]->name);
        self::assertSame('child2', $root->children[1]->name);
    }

    public function testDeeplyNestedSpans(): void
    {
        $this->profiler->start('level1');
        $this->profiler->start('level2');
        $this->profiler->start('level3');
        $this->profiler->stop('level3');
        $this->profiler->stop('level2');
        $this->profiler->stop('level1');

        $root = $this->profiler->getRootSpan();
        self::assertNotNull($root);
        self::assertSame('level1', $root->name);
        self::assertCount(1, $root->children);
        self::assertSame('level2', $root->children[0]->name);
        self::assertCount(1, $root->children[0]->children);
        self::assertSame('level3', $root->children[0]->children[0]->name);
    }

    public function testGetSummaryReturnsEmptyArrayInitially(): void
    {
        self::assertSame([], $this->profiler->getSummary());
    }

    public function testGetSummaryAggregatesStats(): void
    {
        $this->profiler->start('root');
        $this->profiler->start('operation');
        usleep(1000); // Sleep for 1ms
        $this->profiler->stop('operation');

        $this->profiler->start('operation');
        usleep(1000); // Sleep for 1ms
        $this->profiler->stop('operation');
        $this->profiler->stop('root');

        $summary = $this->profiler->getSummary();
        self::assertArrayHasKey('operation', $summary);
        self::assertSame(2, $summary['operation']['count']);
        self::assertGreaterThan(0, $summary['operation']['total']);
        self::assertGreaterThan(0, $summary['operation']['avg']);
    }

    public function testGetSummaryWithNestedSpans(): void
    {
        $this->profiler->start('parent');
        $this->profiler->start('child');
        $this->profiler->stop('child');
        $this->profiler->stop('parent');

        $summary = $this->profiler->getSummary();
        self::assertArrayHasKey('parent', $summary);
        self::assertArrayHasKey('child', $summary);
        self::assertSame(1, $summary['parent']['count']);
        self::assertSame(1, $summary['child']['count']);
    }

    public function testExportJsonFormat(): void
    {
        $this->profiler->start('test');
        $this->profiler->stop('test');

        $json = $this->profiler->export('json');
        $data = json_decode($json, true);

        self::assertIsArray($data);
        self::assertSame('test', $data['name']);
        self::assertArrayHasKey('duration_ms', $data);
        self::assertArrayHasKey('memory_delta_bytes', $data);
    }

    public function testExportChromeTracingFormat(): void
    {
        $this->profiler->start('test');
        $this->profiler->stop('test');

        $json = $this->profiler->export('chrome-tracing');
        $data = json_decode($json, true);

        self::assertIsArray($data);
        self::assertArrayHasKey('traceEvents', $data);
        self::assertCount(2, $data['traceEvents']); // Begin + End
        self::assertSame('B', $data['traceEvents'][0]['ph']);
        self::assertSame('E', $data['traceEvents'][1]['ph']);
    }

    public function testExportThrowsExceptionForUnsupportedFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported export format: invalid');

        // @phpstan-ignore-next-line Testing invalid format
        $this->profiler->export('invalid');
    }

    public function testStopFindsCorrectSpanInStack(): void
    {
        // Test that stop() finds the most recent span with the given name
        $this->profiler->start('outer');
        $this->profiler->start('inner');
        $this->profiler->start('outer'); // Second "outer" span
        $this->profiler->stop('outer'); // Should stop the second "outer"

        $root = $this->profiler->getRootSpan();
        self::assertNotNull($root);
        self::assertSame('outer', $root->name);
        self::assertTrue($root->isRunning()); // First "outer" still running
        self::assertCount(1, $root->children);
        self::assertSame('inner', $root->children[0]->name);
        self::assertTrue($root->children[0]->isRunning()); // "inner" still running
        self::assertCount(1, $root->children[0]->children);
        self::assertSame('outer', $root->children[0]->children[0]->name);
        self::assertFalse($root->children[0]->children[0]->isRunning()); // Second "outer" stopped
    }

    public function testOutOfOrderStopEnforcesLifo(): void
    {
        // Start: outer -> inner1 -> inner2
        // Stop 'inner1' out-of-order (inner2 is on top)
        $this->profiler->start('outer');
        $this->profiler->start('inner1');
        $this->profiler->start('inner2');

        // Stopping 'inner1' should also stop 'inner2' (LIFO enforcement)
        $this->profiler->stop('inner1');

        $root = $this->profiler->getRootSpan();
        self::assertNotNull($root);
        self::assertTrue($root->isRunning()); // outer still running

        // inner1 should be stopped
        $inner1 = $root->children[0];
        self::assertSame('inner1', $inner1->name);
        self::assertFalse($inner1->isRunning());

        // inner2 should also be stopped (LIFO enforcement)
        $inner2 = $inner1->children[0];
        self::assertSame('inner2', $inner2->name);
        self::assertFalse($inner2->isRunning());

        // After the out-of-order stop, new spans should nest under outer
        $this->profiler->start('inner3');
        $this->profiler->stop('inner3');
        $this->profiler->stop('outer');

        self::assertFalse($root->isRunning());
        self::assertCount(2, $root->children); // inner1 and inner3
        self::assertSame('inner3', $root->children[1]->name);
    }

    public function testClearResetsProfiler(): void
    {
        $this->profiler->start('test');
        $this->profiler->start('nested');
        $this->profiler->stop('nested');
        $this->profiler->stop('test');

        self::assertNotNull($this->profiler->getRootSpan());
        self::assertNotEmpty($this->profiler->getSummary());

        $this->profiler->clear();

        self::assertNull($this->profiler->getRootSpan());
        self::assertSame([], $this->profiler->getSummary());
    }

    public function testClearAllowsNewSpansAfterReset(): void
    {
        $this->profiler->start('first');
        $this->profiler->stop('first');
        $this->profiler->clear();

        $this->profiler->start('second');
        $this->profiler->stop('second');

        $root = $this->profiler->getRootSpan();
        self::assertNotNull($root);
        self::assertSame('second', $root->name);
    }
}
