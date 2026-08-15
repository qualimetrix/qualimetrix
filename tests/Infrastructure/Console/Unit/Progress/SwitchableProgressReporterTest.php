<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Progress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Progress\ProgressReporterInterface;
use Qualimetrix\Infrastructure\Console\Progress\SwitchableProgressReporter;

#[CoversClass(SwitchableProgressReporter::class)]
final class SwitchableProgressReporterTest extends TestCase
{
    #[Test]
    public function itDelegatesToTheEnabledReporter(): void
    {
        $reporter = $this->createMock(ProgressReporterInterface::class);
        $reporter->expects(self::once())->method('start')->with(100);
        $reporter->expects(self::once())->method('advance')->with(5);
        $reporter->expects(self::once())->method('setMessage')->with('collecting');
        $reporter->expects(self::once())->method('finish');

        $switch = new SwitchableProgressReporter();
        $switch->enable($reporter);
        $switch->start(100);
        $switch->advance(5);
        $switch->setMessage('collecting');
        $switch->finish();
    }

    #[Test]
    public function itIsANoOpByDefaultAndAfterReset(): void
    {
        $reporter = $this->createMock(ProgressReporterInterface::class);
        $reporter->expects(self::never())->method('advance');

        $switch = new SwitchableProgressReporter();
        $switch->advance();
        $switch->enable($reporter);
        $switch->reset();
        $switch->advance();
    }
}
