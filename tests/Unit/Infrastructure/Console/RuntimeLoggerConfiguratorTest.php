<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Infrastructure\Console\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(RuntimeLoggerConfigurator::class)]
final class RuntimeLoggerConfiguratorTest extends TestCase
{
    #[Test]
    public function itCreatesPublishesAndReturnsTheSameLogger(): void
    {
        $input = self::createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);
        $output = self::createStub(OutputInterface::class);
        $holder = new LoggerHolder();
        $expectedLogger = new NullLogger();
        $factory = self::createStub(LoggerFactoryInterface::class);
        $factory->method('create')->willReturn($expectedLogger);

        $logger = (new RuntimeLoggerConfigurator($factory, $holder))->configure($input, $output);

        self::assertSame($expectedLogger, $logger);
        self::assertSame($logger, $holder->getLogger());
    }
}
