<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;

final class LoggerHolderTest extends TestCase
{
    #[Test]
    public function itInitiallyContainsNullLogger(): void
    {
        $holder = new LoggerHolder();

        $logger = $holder->getLogger();

        self::assertInstanceOf(NullLogger::class, $logger);
    }

    #[Test]
    public function itCanSetCustomLogger(): void
    {
        $holder = new LoggerHolder();
        $customLogger = self::createStub(LoggerInterface::class);

        $holder->setLogger($customLogger);

        self::assertSame($customLogger, $holder->getLogger());
    }

    #[Test]
    public function itCanReplaceLogger(): void
    {
        $holder = new LoggerHolder();

        $firstLogger = self::createStub(LoggerInterface::class);
        $holder->setLogger($firstLogger);
        self::assertSame($firstLogger, $holder->getLogger());

        $secondLogger = self::createStub(LoggerInterface::class);
        $holder->setLogger($secondLogger);
        self::assertSame($secondLogger, $holder->getLogger());
    }

}
