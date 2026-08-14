<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
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

        $logger = (new RuntimeLoggerConfigurator(new LoggerFactory(), $holder))->configure($input, $output);

        self::assertSame($logger, $holder->getLogger());
    }
}
