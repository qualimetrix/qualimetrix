<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Profiler\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Profiler\Profiler;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;

/**
 * The profiler across a whole run: a session that only records once enabled, a
 * span tree collected over several phases, and the two export formats read back
 * through `Profiler::export()` rather than through an exporter directly.
 *
 * Every case here works in memory.
 */
#[CoversClass(Profiler::class)]
#[CoversClass(ProfileSession::class)]
final class ProfilerWorkflowTest extends TestCase
{
    #[Test]
    public function itStartsAsADisabledEmptySession(): void
    {
        $profiler = new ProfileSession();

        self::assertFalse($profiler->isEnabled());
        self::assertSame([], $profiler->summary()->spans);
    }

    #[Test]
    public function itIgnoresInstrumentationWhileDisabled(): void
    {
        $profiler = new ProfileSession();
        $profiler->start('ignored');
        $profiler->stop('ignored');

        self::assertSame([], $profiler->summary()->spans);
    }

    #[Test]
    public function itCollectsSpansDuringSimulatedAnalysis(): void
    {
        $profiler = new Profiler();
        // Simulate analysis phases
        $profiler->start('analysis', 'pipeline');

        $profiler->start('discovery', 'pipeline');
        usleep(1000); // 1ms
        $profiler->stop('discovery');

        $profiler->start('collection', 'pipeline');
        usleep(2000); // 2ms
        $profiler->stop('collection');

        $profiler->start('rules', 'pipeline');
        usleep(1000); // 1ms
        $profiler->stop('rules');

        $profiler->stop('analysis');

        // Verify span tree
        $root = $profiler->getRootSpan();
        self::assertNotNull($root);
        self::assertSame('analysis', $root->name);
        self::assertSame('pipeline', $root->category);
        self::assertCount(3, $root->children);

        // Verify summary
        $summary = $profiler->getSummary();
        self::assertArrayHasKey('analysis', $summary);
        self::assertArrayHasKey('discovery', $summary);
        self::assertArrayHasKey('collection', $summary);
        self::assertArrayHasKey('rules', $summary);

        self::assertSame(1, $summary['analysis']['count']);
        self::assertGreaterThan(0, $summary['analysis']['total']);
    }

    #[Test]
    public function itExportsToJsonFormat(): void
    {
        $profiler = new Profiler();
        $profiler->start('test', 'category');
        $profiler->start('child', 'category');
        $profiler->stop('child');
        $profiler->stop('test');

        $json = $profiler->export('json');
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        // Verify JSON structure
        self::assertIsArray($data);
        self::assertSame('test', $data['name']);
        self::assertSame('category', $data['category']);
        self::assertArrayHasKey('duration_ms', $data);
        self::assertArrayHasKey('memory_delta_bytes', $data);
        self::assertArrayHasKey('peak_memory_delta_bytes', $data);
        self::assertArrayHasKey('children', $data);
        self::assertCount(1, $data['children']);
        self::assertSame('child', $data['children'][0]['name']);
    }

    #[Test]
    public function itExportsToChromeTracingFormat(): void
    {
        $profiler = new Profiler();
        $profiler->start('test', 'category');
        $profiler->stop('test');

        $json = $profiler->export('chrome-tracing');
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        // Verify Chrome Tracing structure
        self::assertIsArray($data);
        self::assertArrayHasKey('traceEvents', $data);
        self::assertCount(2, $data['traceEvents']); // Begin + End events

        $beginEvent = $data['traceEvents'][0];
        $endEvent = $data['traceEvents'][1];

        self::assertSame('test', $beginEvent['name']);
        self::assertSame('B', $beginEvent['ph']); // Begin
        self::assertSame('category', $beginEvent['cat']);

        self::assertSame('test', $endEvent['name']);
        self::assertSame('E', $endEvent['ph']); // End
    }

    #[Test]
    public function itCountsBothSpansForNestedSpansWithSameName(): void
    {
        $profiler = new Profiler();

        // Simulate recursive file processing
        $profiler->start('process_file', 'collection');
        $profiler->start('process_file', 'collection'); // Nested with same name
        $profiler->stop('process_file'); // Stops inner
        $profiler->stop('process_file'); // Stops outer

        $summary = $profiler->getSummary();

        // Both spans should be counted
        self::assertSame(2, $summary['process_file']['count']);
    }
}
