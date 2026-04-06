<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * PSR-3 logger that outputs to Symfony Console.
 *
 * Respects console verbosity levels:
 * - QUIET: no logs
 * - NORMAL: warnings and errors only
 * - VERBOSE: info, warnings, and errors
 * - VERY_VERBOSE: debug and above
 * - DEBUG: all logs
 */
final class ConsoleLogger extends AbstractLogger
{
    use LoggerHelperTrait;

    /**
     * @param OutputInterface $output Console output interface
     * @param string $minLevel Minimum log level to output (default: INFO)
     */
    public function __construct(
        private readonly OutputInterface $output,
        private readonly string $minLevel = LogLevel::INFO,
    ) {}

    /**
     * @param string $level Log level
     * @param string|Stringable $message Log message
     * @param array<string, mixed> $context Additional context
     */
    // @phpstan-ignore-next-line method.childParameterType
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!$this->meetsMinLevel($level, $this->minLevel)) {
            return;
        }

        $formatted = $this->format($level, (string) $message, $context);

        match ($level) {
            LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::EMERGENCY
                => $this->output->writeln("<error>{$formatted}</error>", OutputInterface::VERBOSITY_NORMAL),
            LogLevel::WARNING
                => $this->output->writeln("<comment>{$formatted}</comment>", OutputInterface::VERBOSITY_NORMAL),
            LogLevel::INFO, LogLevel::NOTICE
                => $this->output->writeln("<info>{$formatted}</info>", OutputInterface::VERBOSITY_VERBOSE),
            default
            => $this->output->writeln($formatted, OutputInterface::VERBOSITY_VERY_VERBOSE),
        };
    }

    /**
     * Formats log message with timestamp, level, and context.
     *
     * Message placeholders are interpolated per PSR-3 spec.
     * Full context is appended as JSON for machine readability.
     *
     * @param array<string, mixed> $context
     */
    private function format(string $level, string $message, array $context): string
    {
        $timestamp = date('H:i:s');
        $levelUpper = strtoupper($level);

        $message = $this->interpolate($message, $context);

        $contextStr = '';
        if ($context !== []) {
            $contextStr = ' ' . json_encode($context, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        return "[{$timestamp}] [{$levelUpper}] {$message}{$contextStr}";
    }
}
