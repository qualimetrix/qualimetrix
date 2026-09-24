<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The former role-first suite duplicated the quiet, default-console, and
 * verbose scenarios. Their assertion union is retained here without separate
 * duplicate test methods.
 */
#[CoversClass(LoggerFactory::class)]
final class LoggerFactoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function itCreatesConsoleLoggerAtDefaultVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        $logger = (new LoggerFactory())->create($output);

        self::assertNotInstanceOf(NullLogger::class, $logger);

        $logger->warning('Test warning');
        self::assertStringContainsString('Test warning', $output->fetch());

        $logger->info('Test info');
        self::assertSame('', $output->fetch());
    }

    #[Test]
    public function itCreatesNullLoggerWhenQuiet(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);

        $logger = (new LoggerFactory())->create($output);

        self::assertInstanceOf(NullLogger::class, $logger);
    }

    #[Test]
    public function itCreatesConsoleLoggerWithVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $logger = (new LoggerFactory())->create($output);

        $logger->info('Test message');
        self::assertStringContainsString('Test message', $output->fetch());
    }

    #[Test]
    public function itCreatesFileLoggerWhenPathProvided(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logFile = $this->tempDir . '/test.log';

        $logger = (new LoggerFactory())->create($output, $logFile);
        $logger->info('Test');

        self::assertFileExists($logFile);
        $content = file_get_contents($logFile);
        self::assertIsString($content);
        self::assertStringContainsString('Test', $content);
    }

    #[Test]
    public function itCreatesCompositeLogger(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logFile = $this->tempDir . '/test.log';

        $logger = (new LoggerFactory())->create($output, $logFile);
        $logger->info('Test message');

        self::assertStringContainsString('Test message', $output->fetch());
        self::assertFileExists($logFile);
        $fileContent = file_get_contents($logFile);
        self::assertIsString($fileContent);
        self::assertStringContainsString('Test message', $fileContent);
    }

    #[Test]
    public function itRespectsConfiguredConsoleLogLevel(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $logger = (new LoggerFactory())->create(
            $output,
            null,
            LogLevel::WARNING,
        );
        $logger->info('Info message');
        $logger->warning('Warning message');

        $content = $output->fetch();
        self::assertStringNotContainsString('Info message', $content);
        self::assertStringContainsString('Warning message', $content);
    }

    /**
     * At `-v` the console takes the configured level, DEBUG included. A
     * second gate in the console logger used to hold DEBUG back until `-vv`,
     * so `--log-level=debug -v` printed no DEBUG line.
     */
    #[Test]
    public function itWritesDebugAtVerboseWhenTheLevelAsksForIt(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        (new LoggerFactory())->create($output, null, LogLevel::DEBUG)->debug('Debug message');

        self::assertStringContainsString('Debug message', $output->fetch());
    }

    #[Test]
    public function itWritesDebugAtVeryVerboseByDefault(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);

        (new LoggerFactory())->create($output)->debug('Debug message');

        self::assertStringContainsString('Debug message', $output->fetch());
    }

    #[Test]
    public function itAppliesLogLevelToFileLogger(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logFile = $this->tempDir . '/test.log';

        $logger = (new LoggerFactory())->create(
            $output,
            $logFile,
            LogLevel::INFO,
        );
        $logger->debug('Debug message');
        $logger->info('Info message');

        self::assertFileExists($logFile);
        $content = file_get_contents($logFile);
        self::assertIsString($content);
        self::assertStringNotContainsString('Debug message', $content);
        self::assertStringContainsString('Info message', $content);
    }

    #[Test]
    public function itHandlesEmptyLogFilePath(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $logger = (new LoggerFactory())->create($output, '');

        $logger->info('Test');
        self::assertStringContainsString('Test', $output->fetch());
    }

    /**
     * The whole matrix, not the one cell that used to work: a written level
     * holds at `-v` and above and narrows a normal-verbosity console, and
     * verbosity chooses only when none was written. Before, the console
     * ignored the level everywhere but `-v`.
     *
     * @param ?string $level the written `--log-level`, null when none
     */
    #[Test]
    #[DataProvider('provideLevelAndVerbosityCases')]
    public function itLetsAWrittenLevelHoldAtEveryVerbosity(int $verbosity, ?string $level, string $expected): void
    {
        $output = new BufferedOutput($verbosity);
        $logger = (new LoggerFactory())->create($output, null, $level);

        foreach ([LogLevel::DEBUG, LogLevel::INFO, LogLevel::WARNING, LogLevel::ERROR] as $message) {
            $logger->log($message, 'line-' . $message);
        }

        $content = $output->fetch();
        $written = '';
        foreach (['D' => LogLevel::DEBUG, 'I' => LogLevel::INFO, 'W' => LogLevel::WARNING, 'E' => LogLevel::ERROR] as $mark => $message) {
            $written .= str_contains($content, 'line-' . $message) ? $mark : '';
        }

        self::assertSame($expected, $written);
    }

    /** @return iterable<string, array{int, ?string, string}> */
    public static function provideLevelAndVerbosityCases(): iterable
    {
        $verbosities = [
            'normal' => OutputInterface::VERBOSITY_NORMAL,
            '-v' => OutputInterface::VERBOSITY_VERBOSE,
            '-vv' => OutputInterface::VERBOSITY_VERY_VERBOSE,
            '-vvv' => OutputInterface::VERBOSITY_DEBUG,
        ];
        $written = ['debug' => 'DIWE', 'info' => 'IWE', 'warning' => 'WE', 'error' => 'E'];
        $writtenAtNormal = ['debug' => 'WE', 'info' => 'WE', 'warning' => 'WE', 'error' => 'E'];
        $unwritten = ['normal' => 'WE', '-v' => 'IWE', '-vv' => 'DIWE', '-vvv' => 'DIWE'];

        foreach ($verbosities as $name => $verbosity) {
            yield \sprintf('%s, no level written', $name) => [$verbosity, null, $unwritten[$name]];
            foreach ($written as $level => $expected) {
                $cell = $name === 'normal' ? $writtenAtNormal[$level] : $expected;
                yield \sprintf('%s, --log-level=%s', $name, $level) => [$verbosity, $level, $cell];
            }
        }
    }

    #[Test]
    public function itGivesTheLogFileInfoWhenNoLevelWasWritten(): void
    {
        $logFile = $this->tempDir . '/test.log';

        $logger = (new LoggerFactory())->create(new BufferedOutput(OutputInterface::VERBOSITY_QUIET), $logFile);
        $logger->debug('Debug message');
        $logger->info('Info message');

        $content = file_get_contents($logFile);
        self::assertIsString($content);
        self::assertStringNotContainsString('Debug message', $content);
        self::assertStringContainsString('Info message', $content);
    }
}
