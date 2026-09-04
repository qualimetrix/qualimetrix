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
     * @param string $level Minimum log level (default: INFO)
     */
    public function create(
        OutputInterface $diagnostics,
        ?string $logFile = null,
        string $level = LogLevel::INFO,
    ): LoggerInterface {
        $loggers = [];

        // Console logger (respects verbosity)
        if (!$diagnostics->isQuiet()) {
            $consoleLevel = match (true) {
                $diagnostics->isDebug() => LogLevel::DEBUG,
                $diagnostics->isVeryVerbose() => LogLevel::DEBUG,
                $diagnostics->isVerbose() => $level,
                default => LogLevel::WARNING,
            };
            $loggers[] = new ConsoleLogger($diagnostics, $consoleLevel);
        }

        // File logger
        if ($logFile !== null && $logFile !== '') {
            $loggers[] = new FileLogger($logFile, $level);
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
}
