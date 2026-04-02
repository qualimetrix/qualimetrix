<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
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
final class LoggerFactory
{
    /**
     * Creates a logger based on output configuration.
     *
     * @param OutputInterface $output Console output interface
     * @param string|null $logFile Optional path to log file
     * @param string $level Minimum log level (default: INFO)
     */
    public function create(
        OutputInterface $output,
        ?string $logFile = null,
        string $level = LogLevel::INFO,
    ): LoggerInterface {
        $loggers = [];

        // Console logger (respects verbosity)
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $loggers[] = new ConsoleLogger($output, $level);
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
            /** @param list<LoggerInterface> $loggers */
            public function __construct(private readonly array $loggers) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                foreach ($this->loggers as $logger) {
                    $logger->log($level, $message, $context);
                }
            }
        };
    }
}
