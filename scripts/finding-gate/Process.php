<?php

declare(strict_types=1);

namespace QmxFindingGate;

final class Process
{
    /**
     * @param list<string> $command
     *
     * @return array{stdout: string, stderr: string, exit: int}
     */
    public static function run(array $command, string $workingDirectory): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $handle = proc_open($command, $descriptors, $pipes, $workingDirectory, self::environment());

        if (!\is_resource($handle)) {
            throw new GateError(\sprintf('Cannot start %s.', implode(' ', $command)));
        }

        [$stdout, $stderr] = self::drain($pipes[1], $pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($handle)];
    }

    /**
     * Both pipes are read as they fill, never one to EOF and then the other.
     *
     * Draining stdout first deadlocks a child that writes more than a pipe
     * buffer to stderr: it blocks on the unread stderr pipe, and we block on the
     * stdout it will never finish. Measured on 2026-08-23 with 1 MiB to stderr —
     * and both gate commands disable Composer's process timeout, so nothing
     * would ever interrupt the wait.
     *
     * @param resource $out
     * @param resource $error
     *
     * @return array{0: string, 1: string}
     */
    private static function drain($out, $error): array
    {
        stream_set_blocking($out, false);
        stream_set_blocking($error, false);
        $open = [1 => $out, 2 => $error];
        $captured = [1 => '', 2 => ''];

        while ($open !== []) {
            $read = array_values($open);
            $write = [];
            $except = [];

            // `false` here is the underlying `select()` syscall failing, most
            // commonly EINTR — a signal delivered mid-call, plausible under
            // the process pressure of several scratch clones running at once.
            // Breaking on it stopped draining with pipes still open, which is
            // a truncated capture: a JSON artifact that stops mid-object,
            // parses as nothing, and used to reach Fingerprints::publishedInSarif()
            // / ::publishedInGitLab() as an uncaught JsonException (the Ш4c
            // долг in docs/internal/plans/rule-vocabulary/PLAN.md). Retrying
            // is the standard EINTR-safe idiom; a truly broken descriptor
            // still terminates the loop the next time `fread()` reports EOF.
            if (stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($open as $stream => $pipe) {
                if (!\in_array($pipe, $read, true)) {
                    continue;
                }

                $chunk = fread($pipe, 65536);

                if ($chunk === false || ($chunk === '' && feof($pipe))) {
                    fclose($pipe);
                    unset($open[$stream]);

                    continue;
                }

                $captured[$stream] .= $chunk;
            }
        }

        foreach ($open as $pipe) {
            fclose($pipe);
        }

        return [$captured[1], $captured[2]];
    }

    /**
     * A run must not inherit anything that could make the two trees disagree for
     * a reason other than their own code.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        return [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'LC_ALL' => 'C',
            'TZ' => 'UTC',
            'COLUMNS' => '120',
            'NO_COLOR' => '1',
        ];
    }
}
