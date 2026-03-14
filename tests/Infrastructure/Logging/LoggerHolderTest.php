<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Infrastructure\Logging;

use AiMessDetector\Infrastructure\Logging\LoggerHolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LoggerHolderTest extends TestCase
{
    public function testInitiallyContainsNullLogger(): void
    {
        $holder = new LoggerHolder();

        $logger = $holder->getLogger();

        $this->assertInstanceOf(NullLogger::class, $logger);
    }

    public function testCanSetCustomLogger(): void
    {
        $holder = new LoggerHolder();
        $customLogger = $this->createStub(LoggerInterface::class);

        $holder->setLogger($customLogger);

        $this->assertSame($customLogger, $holder->getLogger());
    }

    public function testCanReplaceLogger(): void
    {
        $holder = new LoggerHolder();

        $firstLogger = $this->createStub(LoggerInterface::class);
        $holder->setLogger($firstLogger);
        $this->assertSame($firstLogger, $holder->getLogger());

        $secondLogger = $this->createStub(LoggerInterface::class);
        $holder->setLogger($secondLogger);
        $this->assertSame($secondLogger, $holder->getLogger());
    }

    public function testMultipleGettersReturnSameInstance(): void
    {
        $holder = new LoggerHolder();
        $logger = $this->createStub(LoggerInterface::class);

        $holder->setLogger($logger);

        $retrieved1 = $holder->getLogger();
        $retrieved2 = $holder->getLogger();

        $this->assertSame($retrieved1, $retrieved2);
        $this->assertSame($logger, $retrieved1);
    }
}
