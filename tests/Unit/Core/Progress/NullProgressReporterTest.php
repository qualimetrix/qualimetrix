<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Progress;

use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Progress\NullProgressReporter;

final class NullProgressReporterTest extends TestCase
{
    public function testDoesNothing(): void
    {
        self::expectNotToPerformAssertions();

        $reporter = new NullProgressReporter();

        // All methods should be no-ops and not throw
        $reporter->start(100);
        $reporter->advance();
        $reporter->advance(5);
        $reporter->setMessage('test message');
        $reporter->finish();
    }

    public function testCanBeCalledMultipleTimes(): void
    {
        self::expectNotToPerformAssertions();

        $reporter = new NullProgressReporter();

        // Can be started and finished multiple times
        $reporter->start(50);
        $reporter->finish();
        $reporter->start(100);
        $reporter->finish();
    }
}
