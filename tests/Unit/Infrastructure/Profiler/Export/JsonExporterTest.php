<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Profiler\Export;

use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Profiler\Span;
use Qualimetrix\Infrastructure\Profiler\Export\JsonExporter;

final class JsonExporterTest extends TestCase
{
    private JsonExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new JsonExporter();
    }

    public function testExportNullSpanReturnsEmptyArray(): void
    {
        $result = $this->exporter->export([]);

        self::assertSame('[]', $result);
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

        $result = $this->exporter->export([$span]);
        $data = json_decode($result, true);

        self::assertIsArray($data);
        self::assertSame('test', $data['name']);
        self::assertSame('category', $data['category']);
        self::assertEquals(1.0, $data['duration_ms']);
        self::assertSame(150, $data['memory_delta_bytes']);
        self::assertSame([], $data['children']);
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

        $result = $this->exporter->export([$span]);
        $data = json_decode($result, true);

        self::assertNull($data['category']);
    }

    public function testExportRunningSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        $result = $this->exporter->export([$span]);
        $data = json_decode($result, true);

        self::assertNull($data['duration_ms']);
        self::assertNull($data['memory_delta_bytes']);
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

        $result = $this->exporter->export([$parent]);
        $data = json_decode($result, true);

        self::assertSame('parent', $data['name']);
        self::assertCount(1, $data['children']);
        self::assertSame('child', $data['children'][0]['name']);
        self::assertEquals(1.0, $data['children'][0]['duration_ms']);
        self::assertSame(100, $data['children'][0]['memory_delta_bytes']);
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

        $result = $this->exporter->export([$level1]);
        $data = json_decode($result, true);

        self::assertSame('level1', $data['name']);
        self::assertCount(1, $data['children']);
        self::assertSame('level2', $data['children'][0]['name']);
        self::assertCount(1, $data['children'][0]['children']);
        self::assertSame('level3', $data['children'][0]['children'][0]['name']);
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

        $result = $this->exporter->export([$span]);

        // Should not throw
        $decoded = json_decode($result, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
    }
}
