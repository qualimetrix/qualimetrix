<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Profiler\Export;

use AiMessDetector\Core\Profiler\Span;
use AiMessDetector\Infrastructure\Profiler\Export\ChromeTracingExporter;
use PHPUnit\Framework\TestCase;

final class ChromeTracingExporterTest extends TestCase
{
    private ChromeTracingExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new ChromeTracingExporter();
    }

    public function testExportNullSpanReturnsEmptyEvents(): void
    {
        $result = $this->exporter->export(null);
        $data = json_decode($result, true);

        self::assertIsArray($data);
        self::assertArrayHasKey('traceEvents', $data);
        self::assertSame([], $data['traceEvents']);
    }

    public function testExportSingleSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
            endTime: 2000000.0,
            endMemory: 250,
        );

        $result = $this->exporter->export($span);
        $data = json_decode($result, true);

        self::assertArrayHasKey('traceEvents', $data);
        self::assertCount(2, $data['traceEvents']);

        // Begin event
        $begin = $data['traceEvents'][0];
        self::assertSame('test', $begin['name']);
        self::assertSame('B', $begin['ph']);
        self::assertEquals(1000.0, $begin['ts']); // 1000000 ns / 1000 = 1000 μs
        self::assertSame(1, $begin['pid']);
        self::assertSame(1, $begin['tid']);
        self::assertSame('category', $begin['cat']);

        // End event
        $end = $data['traceEvents'][1];
        self::assertSame('test', $end['name']);
        self::assertSame('E', $end['ph']);
        self::assertEquals(2000.0, $end['ts']); // 2000000 ns / 1000 = 2000 μs
        self::assertSame(1, $end['pid']);
        self::assertSame(1, $end['tid']);
        self::assertSame('category', $end['cat']);
    }

    public function testExportSpanWithoutCategory(): void
    {
        $span = new Span(
            name: 'test',
            category: null,
            startTime: 1000000.0,
            startMemory: 100,
            endTime: 2000000.0,
            endMemory: 250,
        );

        $result = $this->exporter->export($span);
        $data = json_decode($result, true);

        self::assertArrayNotHasKey('cat', $data['traceEvents'][0]);
        self::assertArrayNotHasKey('cat', $data['traceEvents'][1]);
    }

    public function testExportRunningSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        $result = $this->exporter->export($span);
        $data = json_decode($result, true);

        // Only begin event, no end event
        self::assertCount(1, $data['traceEvents']);
        self::assertSame('B', $data['traceEvents'][0]['ph']);
    }

    public function testExportNestedSpans(): void
    {
        $parent = new Span(
            name: 'parent',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
            endTime: 3000000.0,
            endMemory: 300,
        );

        $child = new Span(
            name: 'child',
            category: 'category',
            startTime: 1500000.0,
            startMemory: 150,
            endTime: 2500000.0,
            endMemory: 250,
            parent: $parent,
        );

        $parent->children[] = $child;

        $result = $this->exporter->export($parent);
        $data = json_decode($result, true);

        self::assertCount(4, $data['traceEvents']);

        // Parent begin
        self::assertSame('parent', $data['traceEvents'][0]['name']);
        self::assertSame('B', $data['traceEvents'][0]['ph']);
        self::assertEquals(1000.0, $data['traceEvents'][0]['ts']);

        // Child begin
        self::assertSame('child', $data['traceEvents'][1]['name']);
        self::assertSame('B', $data['traceEvents'][1]['ph']);
        self::assertEquals(1500.0, $data['traceEvents'][1]['ts']);

        // Child end
        self::assertSame('child', $data['traceEvents'][2]['name']);
        self::assertSame('E', $data['traceEvents'][2]['ph']);
        self::assertEquals(2500.0, $data['traceEvents'][2]['ts']);

        // Parent end
        self::assertSame('parent', $data['traceEvents'][3]['name']);
        self::assertSame('E', $data['traceEvents'][3]['ph']);
        self::assertEquals(3000.0, $data['traceEvents'][3]['ts']);
    }

    public function testExportDeeplyNestedSpans(): void
    {
        $level1 = new Span(
            name: 'level1',
            category: null,
            startTime: 1000000.0,
            startMemory: 100,
            endTime: 4000000.0,
            endMemory: 400,
        );

        $level2 = new Span(
            name: 'level2',
            category: null,
            startTime: 1500000.0,
            startMemory: 150,
            endTime: 3500000.0,
            endMemory: 350,
            parent: $level1,
        );

        $level3 = new Span(
            name: 'level3',
            category: null,
            startTime: 2000000.0,
            startMemory: 200,
            endTime: 3000000.0,
            endMemory: 300,
            parent: $level2,
        );

        $level1->children[] = $level2;
        $level2->children[] = $level3;

        $result = $this->exporter->export($level1);
        $data = json_decode($result, true);

        self::assertCount(6, $data['traceEvents']);

        // Check order: level1-B, level2-B, level3-B, level3-E, level2-E, level1-E
        $names = array_map(fn($event) => $event['name'], $data['traceEvents']);
        $phases = array_map(fn($event) => $event['ph'], $data['traceEvents']);

        self::assertSame(['level1', 'level2', 'level3', 'level3', 'level2', 'level1'], $names);
        self::assertSame(['B', 'B', 'B', 'E', 'E', 'E'], $phases);
    }

    public function testExportProducesValidJson(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
            endTime: 2000000.0,
            endMemory: 250,
        );

        $result = $this->exporter->export($span);

        // Should not throw
        $decoded = json_decode($result, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
    }
}
