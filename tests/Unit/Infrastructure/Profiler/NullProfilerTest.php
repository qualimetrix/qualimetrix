<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Profiler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Profiler\NullProfiler;

final class NullProfilerTest extends TestCase
{
    private NullProfiler $profiler;

    protected function setUp(): void
    {
        $this->profiler = new NullProfiler();
    }

    #[Test]
    public function itIsNotEnabled(): void
    {
        self::assertFalse($this->profiler->isEnabled());
    }

    #[Test]
    public function itReturnsNullForRootSpan(): void
    {
        self::assertNull($this->profiler->getRootSpan());
    }

    #[Test]
    public function itIgnoresStart(): void
    {
        $this->profiler->start('test', 'category');

        self::assertNull($this->profiler->getRootSpan());
    }

    #[Test]
    public function itIgnoresStop(): void
    {
        $this->profiler->stop('test');

        self::assertNull($this->profiler->getRootSpan());
    }

    #[Test]
    public function itIgnoresSnapshot(): void
    {
        $this->profiler->snapshot();

        self::assertNull($this->profiler->getRootSpan());
    }

    #[Test]
    public function itReturnsEmptySummary(): void
    {
        $this->profiler->start('test');
        $this->profiler->stop('test');

        self::assertSame([], $this->profiler->getSummary());
    }

    #[Test]
    public function itReturnsEmptyStringOnExport(): void
    {
        $this->profiler->start('test');
        $this->profiler->stop('test');

        self::assertSame('', $this->profiler->export('json'));
        self::assertSame('', $this->profiler->export('chrome-tracing'));
    }
}
