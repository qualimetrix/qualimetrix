<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Qualimetrix\Infrastructure\Logging\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(ConsoleLogger::class)]
final class ConsoleLoggerTest extends TestCase
{
    #[Test]
    public function itLogsToOutput(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Test message');

        $content = $output->fetch();
        self::assertStringContainsString('Test message', $content);
        self::assertStringContainsString('[INFO]', $content);
    }

    #[Test]
    public function itRespectsMinLevel(): void
    {
        $output = new BufferedOutput();
        $logger = new ConsoleLogger($output, LogLevel::WARNING);

        $logger->info('Should not appear');
        $logger->warning('Should appear');

        $content = $output->fetch();
        self::assertStringNotContainsString('Should not appear', $content);
        self::assertStringContainsString('Should appear', $content);
    }

    #[Test]
    public function itFormatsContext(): void
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

    #[Test]
    public function itInterpolatesPlaceholders(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Processing {file} ({count} lines)', ['file' => 'test.php', 'count' => 42]);

        $content = $output->fetch();
        self::assertStringContainsString('Processing test.php (42 lines)', $content);
        self::assertStringNotContainsString('{file}', $content);
        self::assertStringNotContainsString('{count}', $content);
    }

    #[Test]
    public function itLogsDifferentLogLevels(): void
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

    /**
     * The minimum level alone decides what is written. A second gate here,
     * bound to verbosity, used to override the level its builder chose: a
     * logger built for DEBUG printed no DEBUG line below `-vv`, and one built
     * for WARNING at `-v` still dropped nothing it should.
     *
     * @param list<string> $written
     * @param list<string> $dropped
     */
    #[Test]
    #[DataProvider('provideMinimumLevelCases')]
    public function itWritesExactlyWhatItsMinimumLevelAdmits(int $verbosity, string $minLevel, array $written, array $dropped): void
    {
        $output = new BufferedOutput($verbosity);
        $logger = new ConsoleLogger($output, $minLevel);

        $logger->debug('debug-line');
        $logger->info('info-line');
        $logger->warning('warning-line');
        $logger->error('error-line');

        $content = $output->fetch();
        foreach ($written as $line) {
            self::assertStringContainsString($line, $content);
        }
        foreach ($dropped as $line) {
            self::assertStringNotContainsString($line, $content);
        }
    }

    /** @return iterable<string, array{int, string, list<string>, list<string>}> */
    public static function provideMinimumLevelCases(): iterable
    {
        yield 'debug at normal verbosity' => [OutputInterface::VERBOSITY_NORMAL, LogLevel::DEBUG, ['debug-line', 'info-line', 'warning-line', 'error-line'], []];
        yield 'debug at -v' => [OutputInterface::VERBOSITY_VERBOSE, LogLevel::DEBUG, ['debug-line', 'info-line', 'warning-line', 'error-line'], []];
        yield 'warning at normal verbosity' => [OutputInterface::VERBOSITY_NORMAL, LogLevel::WARNING, ['warning-line', 'error-line'], ['debug-line', 'info-line']];
        yield 'error at -vvv' => [OutputInterface::VERBOSITY_DEBUG, LogLevel::ERROR, ['error-line'], ['debug-line', 'info-line', 'warning-line']];
    }

    /**
     * Every one of PSR-3's eight levels reaches a normal-verbosity console
     * once admitted. ALERT used to fall into the unstyled `-vv` branch, so
     * "action must be taken immediately" was quieter than a warning.
     */
    #[Test]
    #[DataProvider('providePsrLevels')]
    public function itWritesEveryPsrLevelItAdmits(string $level): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        (new ConsoleLogger($output, LogLevel::DEBUG))->log($level, 'admitted');

        self::assertStringContainsString(\sprintf('[%s] admitted', strtoupper($level)), $output->fetch());
    }

    /** @return iterable<string, array{string}> */
    public static function providePsrLevels(): iterable
    {
        foreach ([LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG] as $level) {
            yield $level => [$level];
        }
    }

    /** PSR-3: a level outside the eight is the caller's error, not a message to drop. */
    #[Test]
    public function itRefusesALevelOutsidePsr3(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown log level "trace".');

        (new ConsoleLogger(new BufferedOutput(), LogLevel::WARNING))->log('trace', 'custom-level message');
    }

    #[Test]
    public function itRefusesAMinimumLevelOutsidePsr3(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ConsoleLogger(new BufferedOutput(), 'verbose'))->error('any');
    }

    /** A style name inside the text is text: the formatter deleted it before. */
    #[Test]
    public function itWritesMarkupInsideTheMessageAsText(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        (new ConsoleLogger($output, LogLevel::WARNING))->warning(
            'Failed to parse file',
            ['error' => 'Unexpected <info> token, expected <T_STRING>'],
        );

        self::assertStringContainsString('"error":"Unexpected <info> token, expected <T_STRING>"', $output->fetch());
    }

    #[Test]
    public function itKeepsAContextThatIsNotValidUtf8(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        (new ConsoleLogger($output, LogLevel::WARNING))->warning('Failed to parse file', ['file' => "src/\xB1\x31.php"]);

        self::assertStringContainsString("\"file\":\"src/\u{FFFD}1.php\"", $output->fetch());
    }

    #[Test]
    public function itSaysSoWhenAContextCannotBeEncoded(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        (new ConsoleLogger($output, LogLevel::WARNING))->warning('Measured {what}', ['what' => 'ratio', 'value' => \INF]);

        self::assertStringContainsString('Measured ratio (context not shown: Inf and NaN cannot be JSON encoded)', $output->fetch());
    }

    /** Only named placeholders are converted: converting every value made NAN raise a PHP warning. */
    #[Test]
    public function itInterpolatesANonFiniteFloat(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        (new ConsoleLogger($output, LogLevel::WARNING))->warning('ratio {value}', ['value' => \NAN]);

        self::assertStringContainsString('ratio NAN', $output->fetch());
    }

    #[Test]
    public function itHandlesEmptyContext(): void
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

    #[Test]
    public function itIncludesTimestampInOutput(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->info('Test');

        $content = $output->fetch();
        // Should contain timestamp pattern like [HH:MM:SS]
        self::assertMatchesRegularExpression('/\[\d{2}:\d{2}:\d{2}\]/', $content);
    }
}
