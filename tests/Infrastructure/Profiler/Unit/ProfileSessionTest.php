<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Profiler\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;

final class ProfileSessionTest extends TestCase
{
    #[Test]
    public function itClearsRecordedStateOnEveryModeTransition(): void
    {
        $session = new ProfileSession();
        $session->enable();
        $session->start('first');
        $session->stop('first');
        self::assertNotSame([], $session->summary()->spans);

        $session->disable();
        self::assertFalse($session->isEnabled());
        self::assertSame([], $session->summary()->spans);

        $session->enable();
        self::assertSame([], $session->summary()->spans);
    }
}
