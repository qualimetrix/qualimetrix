<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging;

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

    #[Test]
    public function itReturnsTheSameInstanceOnMultipleGetterCalls(): void
    {
        $holder = new LoggerHolder();
        $logger = self::createStub(LoggerInterface::class);

        $holder->setLogger($logger);

        $retrieved1 = $holder->getLogger();
        $retrieved2 = $holder->getLogger();

        self::assertSame($retrieved1, $retrieved2);
        self::assertSame($logger, $retrieved1);
    }
}
