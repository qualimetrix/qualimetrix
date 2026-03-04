<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Infrastructure\Logging;

use AiMessDetector\Infrastructure\Logging\ConsoleLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleLoggerTest extends TestCase
{
    public function testLogsToOutput(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Test message');

        $content = $output->fetch();
        $this->assertStringContainsString('Test message', $content);
        $this->assertStringContainsString('[INFO]', $content);
    }

    public function testRespectsMinLevel(): void
    {
        $output = new BufferedOutput();
        $logger = new ConsoleLogger($output, LogLevel::WARNING);

        $logger->info('Should not appear');
        $logger->warning('Should appear');

        $content = $output->fetch();
        $this->assertStringNotContainsString('Should not appear', $content);
        $this->assertStringContainsString('Should appear', $content);
    }

    public function testFormatsContext(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Processing', ['file' => 'test.php', 'count' => 42]);

        $content = $output->fetch();
        $this->assertStringContainsString('Processing', $content);
        $this->assertStringContainsString('"file":"test.php"', $content);
        $this->assertStringContainsString('"count":42', $content);
    }

    public function testDifferentLogLevels(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
        $logger = new ConsoleLogger($output, LogLevel::DEBUG);

        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');

        $content = $output->fetch();
        $this->assertStringContainsString('[DEBUG]', $content);
        $this->assertStringContainsString('[INFO]', $content);
        $this->assertStringContainsString('[WARNING]', $content);
        $this->assertStringContainsString('[ERROR]', $content);
    }

    public function testRespectsVerbosityLevels(): void
    {
        // Normal verbosity - should only show warnings and errors
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logger = new ConsoleLogger($output, LogLevel::DEBUG);

        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');

        $content = $output->fetch();
        $this->assertStringContainsString('[WARNING]', $content);
        // DEBUG and INFO should not appear at NORMAL verbosity
    }

    public function testEmptyContext(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Message without context');

        $content = $output->fetch();
        $this->assertStringContainsString('Message without context', $content);
        // Should not contain "[]" or "{}"
        $this->assertStringNotContainsString('[]', $content);
        $this->assertStringNotContainsString('{}', $content);
    }

    public function testTimestampIncluded(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Test');

        $content = $output->fetch();
        // Should contain timestamp pattern like [HH:MM:SS]
        $this->assertMatchesRegularExpression('/\[\d{2}:\d{2}:\d{2}\]/', $content);
    }
}
