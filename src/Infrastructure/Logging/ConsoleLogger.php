<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * PSR-3 logger that outputs to Symfony Console.
 *
 * The minimum level is the only filter: whoever builds this logger has
 * already weighed verbosity against `--log-level` when choosing it, and a
 * second, verbosity-bound gate here overrode that choice — `--log-level=debug
 * -v` printed no DEBUG line, and ALERT was hidden below `-vv`.
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

        // The text is data, not markup: an unescaped `<info>` inside a
        // parser message was read as a style tag and deleted from it.
        $formatted = OutputFormatter::escape($this->format($level, (string) $message, $context));

        // By rank, not by name: every level at or above ERROR — ALERT
        // included, which a list of names once left out — is styled as one.
        $rank = self::rank($level);
        $this->output->writeln(match (true) {
            $rank >= self::rank(LogLevel::ERROR) => "<error>{$formatted}</error>",
            $rank === self::rank(LogLevel::WARNING) => "<comment>{$formatted}</comment>",
            $rank >= self::rank(LogLevel::INFO) => "<info>{$formatted}</info>",
            default => $formatted,
        });
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
            $encoded = self::encodeJson($context);
            $contextStr = $encoded !== null
                ? ' ' . $encoded
                : \sprintf(' (context not shown: %s)', json_last_error_msg());
        }

        return "[{$timestamp}] [{$levelUpper}] {$message}{$contextStr}";
    }
}
