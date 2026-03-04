<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Console\Progress;

use AiMessDetector\Core\Progress\ProgressReporter;
use AiMessDetector\Infrastructure\Console\Progress\DelegatingProgressReporter;
use AiMessDetector\Infrastructure\Console\Progress\ProgressReporterHolder;
use PHPUnit\Framework\TestCase;

final class DelegatingProgressReporterTest extends TestCase
{
    public function testDelegatesToHeldReporter(): void
    {
        $mockReporter = $this->createMock(ProgressReporter::class);
        $mockReporter->expects($this->once())
            ->method('start')
            ->with(100);
        $mockReporter->expects($this->once())
            ->method('advance')
            ->with(5);
        $mockReporter->expects($this->once())
            ->method('setMessage')
            ->with('test message');
        $mockReporter->expects($this->once())
            ->method('finish');

        $holder = new ProgressReporterHolder();
        $holder->setReporter($mockReporter);

        $delegating = new DelegatingProgressReporter($holder);
        $delegating->start(100);
        $delegating->advance(5);
        $delegating->setMessage('test message');
        $delegating->finish();
    }

    public function testDelegatesToNewReporterAfterChange(): void
    {
        $firstReporter = $this->createMock(ProgressReporter::class);
        $firstReporter->expects($this->once())
            ->method('start')
            ->with(50);

        $secondReporter = $this->createMock(ProgressReporter::class);
        $secondReporter->expects($this->once())
            ->method('advance')
            ->with(1);

        $holder = new ProgressReporterHolder();
        $holder->setReporter($firstReporter);

        $delegating = new DelegatingProgressReporter($holder);
        $delegating->start(50);

        // Change reporter
        $holder->setReporter($secondReporter);
        $delegating->advance();
    }
}
