<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Profiler\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Profiler\Export\JsonExporter;
use Qualimetrix\Infrastructure\Profiler\Span;

final class JsonExporterTest extends TestCase
{
    private JsonExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new JsonExporter();
    }

    /**
     * One top-level shape whatever the run recorded. It used to be `[]`, a
     * bare span object or a list of them depending on the number of roots,
     * so a reader had to know the run to parse its profile.
     */
    #[Test]
    public function itWrapsEveryNumberOfRootsInTheSameObject(): void
    {
        $first = new Span(name: 'analysis', category: null, startTime: 1000000.0, startMemory: 100, endTime: 2000000.0, endMemory: 100);
        $second = new Span(name: 'reporting', category: null, startTime: 2000000.0, startMemory: 100, endTime: 3000000.0, endMemory: 100);

        self::assertSame(['spans' => []], json_decode($this->exporter->export([]), true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame(['analysis'], array_column(self::spans($this->exporter->export([$first])), 'name'));
        self::assertSame(['analysis', 'reporting'], array_column(self::spans($this->exporter->export([$first, $second])), 'name'));
    }

    #[Test]
    public function itSaysWhichSpansDidNotStopThemselves(): void
    {
        $parent = new Span(name: 'parent', category: null, startTime: 1000000.0, startMemory: 100);
        $child = new Span(name: 'child', category: null, startTime: 1500000.0, startMemory: 100);
        $child->attachTo($parent);
        $child->closeWithAncestor(3000000.0, 100);
        $parent->finish(3000000.0, 100);

        $data = self::spans($this->exporter->export([$parent]))[0];

        self::assertTrue($data['stopped']);
        self::assertFalse($data['children'][0]['stopped']);
    }

    /** @return list<array<string, mixed>> */
    private static function spans(string $export): array
    {
        $document = json_decode($export, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(['spans'], array_keys($document));
        self::assertIsList($document['spans']);

        return $document['spans'];
    }

    #[Test]
    public function itExportsSingleSpan(): void
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
        $data = self::spans($result)[0];

        self::assertIsArray($data);
        self::assertSame('test', $data['name']);
        self::assertSame('category', $data['category']);
        self::assertEquals(1.0, $data['duration_ms']);
        self::assertSame(150, $data['memory_delta_bytes']);
        self::assertSame([], $data['children']);
    }

    #[Test]
    public function itExportsSpanWithoutCategory(): void
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
        $data = self::spans($result)[0];

        self::assertNull($data['category']);
    }

    #[Test]
    public function itExportsRunningSpan(): void
    {
        $span = new Span(
            name: 'test',
            category: 'category',
            startTime: 1000000.0,
            startMemory: 100,
        );

        $result = $this->exporter->export([$span]);
        $data = self::spans($result)[0];

        self::assertNull($data['duration_ms']);
        self::assertNull($data['memory_delta_bytes']);
    }

    #[Test]
    public function itExportsNestedSpans(): void
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
        $data = self::spans($result)[0];

        self::assertSame('parent', $data['name']);
        self::assertCount(1, $data['children']);
        self::assertSame('child', $data['children'][0]['name']);
        self::assertEquals(1.0, $data['children'][0]['duration_ms']);
        self::assertSame(100, $data['children'][0]['memory_delta_bytes']);
    }

    #[Test]
    public function itExportsDeeplyNestedSpans(): void
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
        $data = self::spans($result)[0];

        self::assertSame('level1', $data['name']);
        self::assertCount(1, $data['children']);
        self::assertSame('level2', $data['children'][0]['name']);
        self::assertCount(1, $data['children'][0]['children']);
        self::assertSame('level3', $data['children'][0]['children'][0]['name']);
    }

}
