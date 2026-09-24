<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Stringable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Factory for creating appropriate logger instances based on configuration.
 *
 * Creates:
 * - ConsoleLogger if output verbosity is enabled
 * - FileLogger if log file path is provided
 * - Composite logger if both are needed
 * - NullLogger if no logging is configured
 */
final class LoggerFactory implements LoggerFactoryInterface
{
    /**
     * Creates a logger based on output configuration.
     *
     * @param OutputInterface $diagnostics The run's diagnostic writer, already
     *                                     resolved by its owner. This factory does not choose a stream: the
     *                                     error stream has exactly one owner, in the console adapter, and a
     *                                     second opinion here is what let log lines land inside a progress
     *                                     frame.
     * @param string|null $logFile Optional path to log file
     * @param string|null $level The written `--log-level`, or null when none was written
     */
    public function create(
        OutputInterface $diagnostics,
        ?string $logFile = null,
        ?string $level = null,
    ): LoggerInterface {
        $loggers = [];

        if (!$diagnostics->isQuiet()) {
            $loggers[] = new ConsoleLogger($diagnostics, self::consoleLevel($diagnostics, $level));
        }

        // File logger
        if ($logFile !== null && $logFile !== '') {
            $loggers[] = new FileLogger($logFile, $level ?? LogLevel::INFO);
        }

        if ($loggers === []) {
            return new NullLogger();
        }

        if (\count($loggers) === 1) {
            return $loggers[0];
        }

        // Composite logger for multiple outputs
        return new class ($loggers) extends AbstractLogger {
            /** @var list<LoggerInterface> */
            private readonly array $loggers;

            /** @param list<LoggerInterface> $loggers */
            public function __construct(array $loggers)
            {
                $this->loggers = $loggers;
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                foreach ($this->loggers as $logger) {
                    $logger->log($level, $message, $context);
                }
            }
        };
    }

    /**
     * A written level is the console's at `-v` and above:
     * `--log-level=error -vv` shows errors, not debug. At normal verbosity it
     * can only narrow the console below warnings, because
     * `--log-level=debug --log-file=…` asks for a detailed file, not for a
     * terminal flooded without `-v`.
     */
    private static function consoleLevel(OutputInterface $diagnostics, ?string $level): string
    {
        return match (true) {
            $level === null && $diagnostics->isVeryVerbose() => LogLevel::DEBUG,
            $level === null && $diagnostics->isVerbose() => LogLevel::INFO,
            $level === null => LogLevel::WARNING,
            $diagnostics->isVerbose() => $level,
            default => self::stricter($level, LogLevel::WARNING),
        };
    }

    private static function stricter(string $level, string $floor): string
    {
        $order = [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

        return array_search($level, $order, true) >= array_search($floor, $order, true) ? $level : $floor;
    }
}
