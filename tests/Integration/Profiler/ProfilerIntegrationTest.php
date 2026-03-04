<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Integration\Profiler;

use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Infrastructure\Profiler\Profiler;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for profiler functionality.
 *
 * Tests the complete profiling workflow:
 * 1. Enable profiler via ProfilerHolder
 * 2. Collect spans during analysis
 * 3. Export to JSON format
 * 4. Export to Chrome Tracing format
 * 5. Verify atomic file writes
 */
final class ProfilerIntegrationTest extends TestCase
{
    private string $tempDir;
    private string $profilePath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/aimd_profiler_test_' . uniqid();
        mkdir($this->tempDir);
        $this->profilePath = $this->tempDir . '/profile.json';

        // Reset ProfilerHolder to clean state
        ProfilerHolder::reset();
    }

    protected function tearDown(): void
    {
        ProfilerHolder::reset();

        if (file_exists($this->profilePath)) {
            unlink($this->profilePath);
        }

        $chromeTracingPath = $this->tempDir . '/profile.chrome.json';
        if (file_exists($chromeTracingPath)) {
            unlink($chromeTracingPath);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testProfilerHolderReturnsNullProfilerByDefault(): void
    {
        $profiler = ProfilerHolder::get();

        self::assertFalse($profiler->isEnabled());
        self::assertNull($profiler->getRootSpan());
        self::assertSame([], $profiler->getSummary());
    }

    public function testProfilerHolderUsesSetProfiler(): void
    {
        $profiler = new Profiler();
        ProfilerHolder::set($profiler);

        $retrieved = ProfilerHolder::get();

        self::assertTrue($retrieved->isEnabled());
        self::assertSame($profiler, $retrieved);
    }

    public function testProfilerCollectsSpansDuringSimulatedAnalysis(): void
    {
        $profiler = new Profiler();
        ProfilerHolder::set($profiler);

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

    public function testExportToJsonFormat(): void
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
        self::assertArrayHasKey('children', $data);
        self::assertCount(1, $data['children']);
        self::assertSame('child', $data['children'][0]['name']);
    }

    public function testExportToChromeTracingFormat(): void
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

    public function testAtomicFileWrite(): void
    {
        $profiler = new Profiler();
        $profiler->start('test');
        $profiler->stop('test');

        $profileData = $profiler->export('json');

        // Simulate atomic write as in AnalyzeCommand
        $tmpFile = $this->profilePath . '.tmp.' . getmypid();
        file_put_contents($tmpFile, $profileData);
        rename($tmpFile, $this->profilePath);

        // Verify file was written correctly
        self::assertFileExists($this->profilePath);

        $content = file_get_contents($this->profilePath);
        self::assertIsString($content);

        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('test', $data['name']);

        // Temp file should not exist
        self::assertFileDoesNotExist($tmpFile);
    }

    public function testProfilerClearResetsState(): void
    {
        $profiler = new Profiler();
        $profiler->start('test');
        $profiler->stop('test');

        self::assertNotNull($profiler->getRootSpan());

        $profiler->clear();

        self::assertNull($profiler->getRootSpan());
        self::assertSame([], $profiler->getSummary());
    }

    public function testNestedSpansWithSameName(): void
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
