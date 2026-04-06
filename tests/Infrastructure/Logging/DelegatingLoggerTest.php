<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Stringable;

final class DelegatingLoggerTest extends TestCase
{
    public function testDelegatesToLoggerInHolder(): void
    {
        $holder = new LoggerHolder();
        $testLogger = new InMemoryLogger();
        $holder->setLogger($testLogger);

        $delegating = new DelegatingLogger($holder);

        $delegating->info('Test message');

        self::assertCount(1, $testLogger->records);
        self::assertSame('info', $testLogger->records[0]['level']);
        self::assertSame('Test message', $testLogger->records[0]['message']);
    }

    public function testDelegatesToMultipleLogLevels(): void
    {
        $holder = new LoggerHolder();
        $testLogger = new InMemoryLogger();
        $holder->setLogger($testLogger);

        $delegating = new DelegatingLogger($holder);

        $delegating->debug('Debug message');
        $delegating->info('Info message');
        $delegating->warning('Warning message');
        $delegating->error('Error message');

        self::assertCount(4, $testLogger->records);
        self::assertSame('debug', $testLogger->records[0]['level']);
        self::assertSame('info', $testLogger->records[1]['level']);
        self::assertSame('warning', $testLogger->records[2]['level']);
        self::assertSame('error', $testLogger->records[3]['level']);
    }

    public function testDelegatesToContextData(): void
    {
        $holder = new LoggerHolder();
        $testLogger = new InMemoryLogger();
        $holder->setLogger($testLogger);

        $delegating = new DelegatingLogger($holder);

        $delegating->info('Message with context', ['key' => 'value', 'count' => 42]);

        self::assertCount(1, $testLogger->records);
        self::assertSame(['key' => 'value', 'count' => 42], $testLogger->records[0]['context']);
    }

    public function testReactsToDynamicLoggerChange(): void
    {
        $holder = new LoggerHolder();
        $firstLogger = new InMemoryLogger();
        $holder->setLogger($firstLogger);

        $delegating = new DelegatingLogger($holder);

        // First message goes to first logger
        $delegating->info('First message');
        self::assertCount(1, $firstLogger->records);

        // Change logger
        $secondLogger = new InMemoryLogger();
        $holder->setLogger($secondLogger);

        // Second message goes to second logger
        $delegating->info('Second message');
        self::assertCount(1, $firstLogger->records);
        self::assertCount(1, $secondLogger->records);
        self::assertSame('Second message', $secondLogger->records[0]['message']);
    }

    public function testDelegatesToNullLoggerInitially(): void
    {
        $holder = new LoggerHolder(); // Contains NullLogger by default
        $delegating = new DelegatingLogger($holder);

        // Should not throw, NullLogger silently discards
        $delegating->info('Test message');
        $delegating->error('Error message');

        // No exception thrown = test passes
        self::expectNotToPerformAssertions();
    }

    public function testSupportsStringableMessages(): void
    {
        $holder = new LoggerHolder();
        $testLogger = new InMemoryLogger();
        $holder->setLogger($testLogger);

        $delegating = new DelegatingLogger($holder);

        $stringable = new class {
            public function __toString(): string
            {
                return 'Stringable message';
            }
        };

        $delegating->info($stringable);

        self::assertCount(1, $testLogger->records);
        self::assertSame('Stringable message', $testLogger->records[0]['message']);
    }

    public function testSupportsAllPsrLogLevels(): void
    {
        $holder = new LoggerHolder();
        $testLogger = new InMemoryLogger();
        $holder->setLogger($testLogger);

        $delegating = new DelegatingLogger($holder);

        $delegating->emergency('Emergency');
        $delegating->alert('Alert');
        $delegating->critical('Critical');
        $delegating->error('Error');
        $delegating->warning('Warning');
        $delegating->notice('Notice');
        $delegating->info('Info');
        $delegating->debug('Debug');

        self::assertCount(8, $testLogger->records);
        self::assertSame('emergency', $testLogger->records[0]['level']);
        self::assertSame('alert', $testLogger->records[1]['level']);
        self::assertSame('critical', $testLogger->records[2]['level']);
        self::assertSame('error', $testLogger->records[3]['level']);
        self::assertSame('warning', $testLogger->records[4]['level']);
        self::assertSame('notice', $testLogger->records[5]['level']);
        self::assertSame('info', $testLogger->records[6]['level']);
        self::assertSame('debug', $testLogger->records[7]['level']);
    }
}

/**
 * Simple in-memory logger for testing.
 */
final class InMemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
