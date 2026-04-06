<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Qualimetrix\Infrastructure\Logging\ConsoleLogger;
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
        self::assertStringContainsString('Test message', $content);
        self::assertStringContainsString('[INFO]', $content);
    }

    public function testRespectsMinLevel(): void
    {
        $output = new BufferedOutput();
        $logger = new ConsoleLogger($output, LogLevel::WARNING);

        $logger->info('Should not appear');
        $logger->warning('Should appear');

        $content = $output->fetch();
        self::assertStringNotContainsString('Should not appear', $content);
        self::assertStringContainsString('Should appear', $content);
    }

    public function testFormatsContext(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Processing', ['file' => 'test.php', 'count' => 42]);

        $content = $output->fetch();
        self::assertStringContainsString('Processing', $content);
        // Context is still appended as JSON
        self::assertStringContainsString('"file":"test.php"', $content);
        self::assertStringContainsString('"count":42', $content);
    }

    public function testInterpolatesPlaceholders(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Processing {file} ({count} lines)', ['file' => 'test.php', 'count' => 42]);

        $content = $output->fetch();
        self::assertStringContainsString('Processing test.php (42 lines)', $content);
        self::assertStringNotContainsString('{file}', $content);
        self::assertStringNotContainsString('{count}', $content);
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
        self::assertStringContainsString('[DEBUG]', $content);
        self::assertStringContainsString('[INFO]', $content);
        self::assertStringContainsString('[WARNING]', $content);
        self::assertStringContainsString('[ERROR]', $content);
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
        self::assertStringContainsString('[WARNING]', $content);
        // DEBUG and INFO should not appear at NORMAL verbosity
    }

    public function testEmptyContext(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Message without context');

        $content = $output->fetch();
        self::assertStringContainsString('Message without context', $content);
        // Should not contain "[]" or "{}"
        self::assertStringNotContainsString('[]', $content);
        self::assertStringNotContainsString('{}', $content);
    }

    public function testTimestampIncluded(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Test');

        $content = $output->fetch();
        // Should contain timestamp pattern like [HH:MM:SS]
        self::assertMatchesRegularExpression('/\[\d{2}:\d{2}:\d{2}\]/', $content);
    }
}
