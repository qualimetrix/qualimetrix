<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Logging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(LoggerFactory::class)]
final class LoggerFactoryTest extends TestCase
{
    private LoggerFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new LoggerFactory();
    }

    #[Test]
    public function itCreatesConsoleLoggerAtDefaultVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        $logger = $this->factory->create(self::diagnosticConsole($output));

        self::assertNotInstanceOf(NullLogger::class, $logger);
    }

    #[Test]
    public function itCreatesNullLoggerWhenQuiet(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);

        $logger = $this->factory->create(self::diagnosticConsole($output));

        self::assertInstanceOf(NullLogger::class, $logger);
    }

    #[Test]
    public function itShowsWarningAtDefaultVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logger = $this->factory->create(self::diagnosticConsole($output));

        $logger->warning('Something is wrong');

        self::assertStringContainsString('Something is wrong', $output->fetch());
    }

    #[Test]
    public function itHidesInfoAtDefaultVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logger = $this->factory->create(self::diagnosticConsole($output));

        $logger->info('Some info message');

        self::assertSame('', $output->fetch());
    }

    #[Test]
    public function itShowsInfoAtVerboseLevel(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = $this->factory->create(self::diagnosticConsole($output));

        $logger->info('Some info message');

        self::assertStringContainsString('Some info message', $output->fetch());
    }

    private static function diagnosticConsole(BufferedOutput $diagnostics): ConsoleOutput
    {
        $output = new ConsoleOutput($diagnostics->getVerbosity(), false);
        $output->setErrorOutput($diagnostics);

        return $output;
    }
}
