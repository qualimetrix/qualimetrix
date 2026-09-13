<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** One supervised case worker, drained by the bounded scheduler. */
final class ProcessHandle
{
    private const TERMINATION_GRACE_MICROSECONDS = 300_000;

    private static bool $supervisionChecked = false;

    /** @var array<int, resource> */
    private array $open;

    /** @var array<int, string> */
    private array $captured = [1 => '', 2 => ''];

    private ?int $exit = null;

    private float $startedAt;

    private int $processGroup;

    /**
     * @param resource $handle
     * @param array<int, resource> $pipes
     */
    private function __construct(private $handle, array $pipes, int $processGroup)
    {
        $this->open = [1 => $pipes[1], 2 => $pipes[2]];
        $this->startedAt = microtime(true);
        $this->processGroup = $processGroup;

        foreach ($this->open as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    /** @param list<string> $command */
    public static function start(array $command, string $workingDirectory): self
    {
        self::ensureProcessTreeSupervision();

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $handle = proc_open(self::groupedCommand($command), $descriptors, $pipes, $workingDirectory, [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'LC_ALL' => 'C',
            'TZ' => 'UTC',
            'COLUMNS' => '120',
            'NO_COLOR' => '1',
            'TMPDIR' => (string) getenv('TMPDIR'),
        ]);

        if (!\is_resource($handle)) {
            throw new GateError(\sprintf('Cannot start %s.', implode(' ', $command)));
        }

        $status = proc_get_status($handle);
        $pid = $status['pid'];

        if ($pid < 2) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($handle);

            throw new GateError(\sprintf('Cannot supervise %s: invalid process id.', implode(' ', $command)));
        }

        self::waitForOwnProcessGroup($handle, $pid, $command);

        return new self($handle, $pipes, $pid);
    }

    /** @return list<resource> */
    public function openStreams(): array
    {
        return array_values($this->open);
    }

    public function drain(): void
    {
        foreach ($this->open as $stream => $pipe) {
            $chunk = fread($pipe, 65536);

            if (\is_string($chunk) && $chunk !== '') {
                $this->captured[$stream] .= $chunk;
            }

            if (feof($pipe)) {
                fclose($pipe);
                unset($this->open[$stream]);
            }
        }

        if ($this->exit === null && \is_resource($this->handle)) {
            $status = proc_get_status($this->handle);

            if ($status['running'] === false) {
                $this->exit = $status['exitcode'];
            }
        }
    }

    public function settled(): bool
    {
        return $this->open === [] && $this->exit !== null;
    }

    /** @return array{stdout: string, stderr: string, exit: int} */
    public function reap(): array
    {
        if ($this->exit === null) {
            throw new GateError('Cannot reap a case worker before it exits.');
        }

        if (\is_resource($this->handle)) {
            proc_close($this->handle);
        }

        return ['stdout' => $this->captured[1], 'stderr' => $this->captured[2], 'exit' => $this->exit];
    }

    public function age(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function terminate(): void
    {
        if (!\is_resource($this->handle)) {
            return;
        }

        self::signalProcessGroup($this->processGroup, \defined('SIGTERM') ? \SIGTERM : 15);

        $this->waitForExit(self::TERMINATION_GRACE_MICROSECONDS);
        self::signalProcessGroup($this->processGroup, \defined('SIGKILL') ? \SIGKILL : 9);
        $this->waitForExit(self::TERMINATION_GRACE_MICROSECONDS);
        self::waitForProcessGroupExit($this->processGroup, self::TERMINATION_GRACE_MICROSECONDS);

        if ($this->settled()) {
            $this->reap();
        }
    }

    private function waitForExit(int $microseconds): void
    {
        $deadline = microtime(true) + ($microseconds / 1_000_000);

        do {
            $this->drain();

            if ($this->settled()) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);
    }

    private static function ensureProcessTreeSupervision(): void
    {
        if (self::$supervisionChecked) {
            return;
        }

        if (!\extension_loaded('posix')) {
            throw new GateError('Process supervision requires the PHP POSIX extension.');
        }

        self::$supervisionChecked = true;
    }

    /** @param list<string> $command
     * @return list<string>
     */
    private static function groupedCommand(array $command): array
    {
        $launcher = <<<'PHP'
            if (posix_setsid() < 0) {
                fwrite(STDERR, "finding-gate: cannot create an isolated process group.\n");
                exit(125);
            }
            $process = proc_open(array_slice($argv, 1), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
            if (!is_resource($process)) {
                fwrite(STDERR, "finding-gate: cannot start the supervised command.\n");
                exit(125);
            }
            exit(proc_close($process));
            PHP;

        return [\PHP_BINARY, '-r', $launcher, ...$command];
    }

    /** @param resource $handle
     * @param list<string> $command
     */
    private static function waitForOwnProcessGroup($handle, int $pid, array $command): void
    {
        $deadline = microtime(true) + (self::TERMINATION_GRACE_MICROSECONDS / 1_000_000);

        do {
            if (@posix_getpgid($pid) === $pid) {
                return;
            }

            $status = proc_get_status($handle);
            if ($status['running'] === false) {
                return;
            }

            usleep(1_000);
        } while (microtime(true) < $deadline);

        proc_terminate($handle);

        throw new GateError(\sprintf('Cannot isolate the process group for %s.', implode(' ', $command)));
    }

    private static function signalProcessGroup(int $processGroup, int $signal): void
    {
        if (@posix_kill(-$processGroup, $signal)) {
            return;
        }

        $error = posix_get_last_error();
        if ($error !== 3) {
            throw new GateError(\sprintf('Cannot signal process group %d: %s.', $processGroup, posix_strerror($error)));
        }
    }

    private static function waitForProcessGroupExit(int $processGroup, int $microseconds): void
    {
        $deadline = microtime(true) + ($microseconds / 1_000_000);

        do {
            if (!@posix_kill(-$processGroup, 0) && posix_get_last_error() !== 1) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new GateError(\sprintf('Process group %d survived SIGKILL.', $processGroup));
    }
}
