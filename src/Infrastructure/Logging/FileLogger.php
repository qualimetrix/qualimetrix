<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;

/**
 * PSR-3 logger that writes to a file in JSON Lines format.
 *
 * Each log entry is a JSON object on a single line:
 * {"timestamp":"2025-12-08T10:15:30+00:00","level":"info","message":"...","context":{...}}
 */
final class FileLogger extends AbstractLogger
{
    use LoggerHelperTrait;

    /** @var resource|null */
    private $handle = null;

    /**
     * @param string $path Path to log file (directories will be created)
     * @param string $minLevel Minimum log level to write (default: DEBUG)
     *
     * @throws RuntimeException If the log file cannot be opened
     */
    public function __construct(
        string $path,
        private readonly string $minLevel = LogLevel::DEBUG,
    ) {
        $dir = \dirname($path);
        if ($dir !== '' && !is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException(\sprintf('Failed to create log directory: %s', $dir));
            }
        }

        $handle = fopen($path, 'a');
        if ($handle === false) {
            throw new RuntimeException(\sprintf('Cannot open log file: %s', $path));
        }

        $this->handle = $handle;
    }

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

        if ($this->handle === null) {
            return;
        }

        $line = json_encode([
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $this->interpolate((string) $message, $context),
            'context' => $context,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";

        fwrite($this->handle, $line);
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
}
