<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Logging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(LoggerFactory::class)]
final class LoggerFactoryTest extends TestCase
{
    private LoggerFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new LoggerFactory();
    }

    public function testCreateReturnsConsoleLoggerAtDefaultVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        $logger = $this->factory->create($output);

        self::assertNotInstanceOf(NullLogger::class, $logger);
    }

    public function testCreateReturnsNullLoggerWhenQuiet(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);

        $logger = $this->factory->create($output);

        self::assertInstanceOf(NullLogger::class, $logger);
    }

    public function testWarningVisibleAtDefaultVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logger = $this->factory->create($output);

        $logger->warning('Something is wrong');

        self::assertStringContainsString('Something is wrong', $output->fetch());
    }

    public function testInfoNotVisibleAtDefaultVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logger = $this->factory->create($output);

        $logger->info('Some info message');

        self::assertSame('', $output->fetch());
    }

    public function testInfoVisibleAtVerboseLevel(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = $this->factory->create($output);

        $logger->info('Some info message');

        self::assertStringContainsString('Some info message', $output->fetch());
    }
}
