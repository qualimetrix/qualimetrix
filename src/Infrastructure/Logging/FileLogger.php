<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Qualimetrix\Infrastructure\Logging\Contract\LogFileUnavailable;
use RuntimeException;
use Stringable;

/**
 * PSR-3 logger that writes to a file in JSON Lines format.
 *
 * Each log entry is a JSON object on a single line:
 * {"timestamp":"2025-12-08T10:15:30+00:00","level":"info","message":"...","context":{...}}
 *
 * A record whose context cannot be encoded keeps its line: `context` is
 * `null` and `context_error` says why.
 */
final class FileLogger extends AbstractLogger
{
    use LoggerHelperTrait;

    /** @var resource|null */
    private $handle = null;

    /**
     * A path this process cannot write is reported once, as
     * {@see LogFileUnavailable} for the caller to answer as the input it was:
     * the failed call's own PHP warning is captured, because under
     * `display_errors=1` it reaches stdout ahead of a machine format's
     * document.
     *
     * @param string $path Path to log file (directories will be created)
     * @param string $minLevel Minimum log level to write (default: DEBUG)
     *
     * @throws LogFileUnavailable If the log file cannot be created or opened
     */
    public function __construct(
        private readonly string $path,
        private readonly string $minLevel = LogLevel::DEBUG,
    ) {
        self::rank($minLevel);

        $dir = \dirname($path);
        if ($dir !== '' && !is_dir($dir)) {
            [$created, $reason] = self::attempt(static fn(): bool => mkdir($dir, 0755, true));
            if (!$created && !is_dir($dir)) {
                throw new LogFileUnavailable($path, \sprintf('whose directory "%s" cannot be created: %s', $dir, $reason));
            }
        }

        [$handle, $reason] = self::attempt(static fn() => fopen($path, 'a'));
        if (!\is_resource($handle)) {
            throw new LogFileUnavailable($path, \sprintf('which cannot be opened for appending: %s', $reason));
        }

        $this->handle = $handle;
    }

    /**
     * @param string $level Log level
     * @param string|Stringable $message Log message
     * @param array<string, mixed> $context Additional context
     *
     * @throws RuntimeException If the record could not be written whole
     */
    // @phpstan-ignore-next-line method.childParameterType
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!$this->meetsMinLevel($level, $this->minLevel)) {
            return;
        }

        if ($this->handle === null) {
            return;
        }

        $record = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $this->interpolate((string) $message, $context),
            'context' => $context,
        ];

        $line = self::encodeJson($record);
        if ($line === null) {
            $record['context'] = null;
            $record['context_error'] = json_last_error_msg();
            $line = self::encodeJson($record) ?? throw new RuntimeException('A log record without context must encode.');
        }
        $line .= "\n";

        // A short write leaves a truncated line in a format where every line
        // is a whole document; the run stops rather than keep a log that
        // lies about what it holds.
        $handle = $this->handle;
        [$written] = self::attempt(static fn(): int|false => fwrite($handle, $line));
        if ($written !== \strlen($line)) {
            throw new RuntimeException(\sprintf(
                'Failed to write the log file %s: %d of %d bytes of a record were written.',
                $this->path,
                $written === false ? 0 : $written,
                \strlen($line),
            ));
        }
    }

    /**
     * Close the log file handle on destruction.
     */
    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Runs a filesystem call with its PHP warning captured rather than
     * printed, and returns the warning's reason for the refusal to quote.
     * A handler of its own, not `@` and `error_get_last()`: an outer handler
     * that answers the warning leaves `error_get_last()` empty.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return array{T, string}
     */
    private static function attempt(callable $operation): array
    {
        $reason = 'unknown reason';
        set_error_handler(static function (int $level, string $message) use (&$reason): bool {
            $reason = preg_match('~^.*: (.+)$~s', $message, $match) === 1 ? $match[1] : $message;

            return true;
        });

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $reason];
    }
}
