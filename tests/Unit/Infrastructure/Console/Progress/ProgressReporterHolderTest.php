<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console\Progress;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Progress\NullProgressReporter;
use Qualimetrix\Infrastructure\Console\Progress\ProgressReporterHolder;

final class ProgressReporterHolderTest extends TestCase
{
    #[Test]
    public function itInitializesWithNullProgressReporter(): void
    {
        $holder = new ProgressReporterHolder();

        self::assertInstanceOf(NullProgressReporter::class, $holder->getReporter());
    }

    #[Test]
    public function itCanSetAndGetReporter(): void
    {
        $holder = new ProgressReporterHolder();
        $reporter = new NullProgressReporter();

        $holder->setReporter($reporter);

        self::assertSame($reporter, $holder->getReporter());
    }

    #[Test]
    public function itCanReplaceReporter(): void
    {
        $holder = new ProgressReporterHolder();
        $reporter1 = new NullProgressReporter();
        $reporter2 = new NullProgressReporter();

        $holder->setReporter($reporter1);
        self::assertSame($reporter1, $holder->getReporter());

        $holder->setReporter($reporter2);
        self::assertSame($reporter2, $holder->getReporter());
    }
}
