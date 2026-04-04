<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class LoggerFactoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory
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

    public function testCreatesConsoleLoggerAtDefaultVerbosity(): void
    {
        $factory = new LoggerFactory();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        $logger = $factory->create($output);

        // At default verbosity, warnings should be visible
        $logger->warning('Test warning');
        $content = $output->fetch();
        $this->assertStringContainsString('Test warning', $content);

        // But info should NOT be visible
        $logger->info('Test info');
        $content = $output->fetch();
        $this->assertStringNotContainsString('Test info', $content);
    }

    public function testCreatesNullLoggerWhenQuiet(): void
    {
        $factory = new LoggerFactory();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);

        $logger = $factory->create($output);

        $this->assertInstanceOf(NullLogger::class, $logger);
    }

    public function testCreatesConsoleLoggerWithVerbosity(): void
    {
        $factory = new LoggerFactory();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $logger = $factory->create($output);

        // Logger should log to output
        $logger->info('Test message');
        $content = $output->fetch();
        $this->assertStringContainsString('Test message', $content);
    }

    public function testCreatesFileLoggerWhenPathProvided(): void
    {
        $factory = new LoggerFactory();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logFile = $this->tempDir . '/test.log';

        $logger = $factory->create($output, $logFile);

        $logger->info('Test');

        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $this->assertIsString($content);
        $this->assertStringContainsString('Test', $content);
    }

    public function testCreatesCompositeLogger(): void
    {
        $factory = new LoggerFactory();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logFile = $this->tempDir . '/test.log';

        $logger = $factory->create($output, $logFile);

        $logger->info('Test message');

        // Should log to both console and file
        $consoleContent = $output->fetch();
        $this->assertStringContainsString('Test message', $consoleContent);

        $this->assertFileExists($logFile);
        $fileContent = file_get_contents($logFile);
        $this->assertIsString($fileContent);
        $this->assertStringContainsString('Test message', $fileContent);
    }

    public function testRespectsLogLevel(): void
    {
        $factory = new LoggerFactory();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $logger = $factory->create($output, null, LogLevel::WARNING);

        $logger->info('Info message');
        $logger->warning('Warning message');

        $content = $output->fetch();
        $this->assertStringNotContainsString('Info message', $content);
        $this->assertStringContainsString('Warning message', $content);
    }

    public function testFileLoggerRespectsLogLevel(): void
    {
        $factory = new LoggerFactory();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logFile = $this->tempDir . '/test.log';

        // File logger should respect the configured log level
        $logger = $factory->create($output, $logFile, LogLevel::INFO);

        $logger->debug('Debug message');
        $logger->info('Info message');

        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $this->assertIsString($content);
        $this->assertStringNotContainsString('Debug message', $content);
        $this->assertStringContainsString('Info message', $content);
    }

    public function testHandlesEmptyLogFile(): void
    {
        $factory = new LoggerFactory();
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $logger = $factory->create($output, '');

        // Should only create console logger
        $logger->info('Test');
        $content = $output->fetch();
        $this->assertStringContainsString('Test', $content);
    }
}
