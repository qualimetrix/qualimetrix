<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Profiler;

use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Profiler\NullProfiler;

final class NullProfilerTest extends TestCase
{
    private NullProfiler $profiler;

    protected function setUp(): void
    {
        $this->profiler = new NullProfiler();
    }

    public function testIsEnabledReturnsFalse(): void
    {
        self::assertFalse($this->profiler->isEnabled());
    }

    public function testGetRootSpanReturnsNull(): void
    {
        self::assertNull($this->profiler->getRootSpan());
    }

    public function testStartIsNoOp(): void
    {
        $this->profiler->start('test', 'category');

        self::assertNull($this->profiler->getRootSpan());
    }

    public function testStopIsNoOp(): void
    {
        $this->profiler->stop('test');

        self::assertNull($this->profiler->getRootSpan());
    }

    public function testSnapshotIsNoOp(): void
    {
        $this->profiler->snapshot();

        self::assertNull($this->profiler->getRootSpan());
    }

    public function testGetSummaryReturnsEmptyArray(): void
    {
        $this->profiler->start('test');
        $this->profiler->stop('test');

        self::assertSame([], $this->profiler->getSummary());
    }

    public function testExportReturnsEmptyString(): void
    {
        $this->profiler->start('test');
        $this->profiler->stop('test');

        self::assertSame('', $this->profiler->export('json'));
        self::assertSame('', $this->profiler->export('chrome-tracing'));
    }
}
