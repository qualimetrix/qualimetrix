<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Support;

use RuntimeException;

/**
 * Runs the real binary with its error stream on a pseudo-terminal.
 *
 * A pipe answers `isDecorated()` with `false`, which switches the progress
 * frame off — the run this package is about cannot happen behind a pipe. Only
 * stderr gets the terminal: stdout stays a pipe, which is both the shape of the
 * run that matters (`qmx check --format=json > report.json` in a terminal) and
 * what keeps the payload readable back as bytes.
 */
final readonly class PseudoTerminalRun
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
    ) {}

    /** Whether this PHP build can allocate a pseudo-terminal at all. */
    public static function isSupported(): bool
    {
        $process = @proc_open(
            \PHP_BINARY . ' -r ' . escapeshellarg('exit(0);'),
            [0 => ['pty'], 1 => ['pipe', 'w'], 2 => ['pty']],
            $pipes,
        );

        if (!\is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        return true;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    public static function execute(
        array $arguments,
        string $workingDirectory,
        array $environment = [],
        int $columns = 120,
    ): self {
        $descriptors = [0 => ['pty'], 1 => ['pipe', 'w'], 2 => ['pty']];
        $environment = array_merge([
            'COLUMNS' => (string) $columns,
            'LINES' => '50',
            'TERM' => 'xterm-256color',
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
        ], $environment);

        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $arguments)),
            $descriptors,
            $pipes,
            $workingDirectory,
            $environment,
        );

        if (!\is_resource($process)) {
            throw new RuntimeException('Could not start the process on a pseudo-terminal');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $collected = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open !== []) {
            $readable = array_values($open);
            $writable = null;
            $except = null;
            if (@stream_select($readable, $writable, $except, 30) === false) {
                break;
            }

            foreach ($readable as $stream) {
                $key = array_search($stream, $open, true);
                if ($key === false) {
                    continue;
                }

                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$key]);
                    }

                    continue;
                }

                $collected[$key] .= $chunk;
            }
        }

        return new self($collected[1], $collected[2], proc_close($process));
    }

    public function screen(int $columns = 120): TerminalScreen
    {
        return TerminalScreen::replay($this->stderr, $columns);
    }
}
