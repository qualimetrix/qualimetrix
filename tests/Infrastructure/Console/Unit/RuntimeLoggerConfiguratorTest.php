<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\Logging\Contract\LogFileUnavailable;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

        $logger = (new RuntimeLoggerConfigurator($factory, $holder, new ErrorStream()))->configure($input, $output);

        self::assertSame($expectedLogger, $logger);
        self::assertSame($logger, $holder->getLogger());
    }

    /**
     * A level the factory cannot build used to become `info`, so a run asked
     * for one verbosity and silently produced another.
     */
    #[Test]
    public function itRefusesALogLevelItCannotBuild(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Invalid value "verbose" for --log-level. Expected one of: debug, info, warning, error.');

        $this->configurator()->configure(self::logLevelInput('verbose'), self::createStub(OutputInterface::class));
    }

    #[Test]
    public function itPassesADeclaredLogLevelToTheFactory(): void
    {
        $factory = $this->createMock(LoggerFactoryInterface::class);
        $factory->expects(self::once())
            ->method('create')
            ->with(self::anything(), null, 'warning')
            ->willReturn(new NullLogger());

        (new RuntimeLoggerConfigurator($factory, new LoggerHolder(), new ErrorStream()))
            ->configure(self::logLevelInput('WARNING'), self::createStub(OutputInterface::class));
    }

    /** Not written is not `info`: only an unwritten level lets verbosity choose the console's. */
    #[Test]
    public function itPassesNoLevelToTheFactoryWhenNoneWasWritten(): void
    {
        $factory = $this->createMock(LoggerFactoryInterface::class);
        $factory->expects(self::once())
            ->method('create')
            ->with(self::anything(), null, null)
            ->willReturn(new NullLogger());

        (new RuntimeLoggerConfigurator($factory, new LoggerHolder(), new ErrorStream()))
            ->configure(
                new ArrayInput([], new InputDefinition([new InputOption('log-level', null, InputOption::VALUE_REQUIRED)])),
                self::createStub(OutputInterface::class),
            );
    }

    /**
     * An unwritable log path is the user's input to fix (exit 3), not an
     * internal error (exit 1).
     */
    #[Test]
    public function itRefusesALogFileTheFactoryCannotWrite(): void
    {
        $factory = self::createStub(LoggerFactoryInterface::class);
        $factory->method('create')->willThrowException(
            new LogFileUnavailable('/ro/qmx.log', 'which cannot be opened for appending: Permission denied'),
        );

        try {
            (new RuntimeLoggerConfigurator($factory, new LoggerHolder(), new ErrorStream()))
                ->configure(self::logLevelInput('info'), self::createStub(OutputInterface::class));
            self::fail('An unwritable log file was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(
                'Option --log-file names "/ro/qmx.log", which cannot be opened for appending: Permission denied.',
                $refusal->summary(),
            );
            self::assertSame('--log-file', $refusal->origin()->locator());
        }
    }

    #[Test]
    public function itPutsBackTheStartingLoggerOnReset(): void
    {
        $holder = new LoggerHolder();
        $holder->setLogger(self::createStub(\Psr\Log\LoggerInterface::class));

        (new RuntimeLoggerConfigurator(self::createStub(LoggerFactoryInterface::class), $holder, new ErrorStream()))->reset();

        self::assertInstanceOf(NullLogger::class, $holder->getLogger());
    }

    private function configurator(): RuntimeLoggerConfigurator
    {
        $factory = self::createStub(LoggerFactoryInterface::class);
        $factory->method('create')->willReturn(new NullLogger());

        return new RuntimeLoggerConfigurator($factory, new LoggerHolder(), new ErrorStream());
    }

    private static function logLevelInput(string $level): ArrayInput
    {
        return new ArrayInput(
            ['--log-level' => $level],
            new InputDefinition([new InputOption('log-level', null, InputOption::VALUE_REQUIRED)]),
        );
    }
}
