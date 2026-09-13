<?php

declare(strict_types=1);

namespace QmxFindingGate;

final class Process
{
    private const COMMAND_DEADLINE_SECONDS = 300;

    private const HEARTBEAT_SECONDS = 30;

    /**
     * @param list<string> $command
     *
     * @return array{stdout: string, stderr: string, exit: int}
     */
    public static function run(
        array $command,
        string $workingDirectory,
        float $deadlineSeconds = self::COMMAND_DEADLINE_SECONDS,
    ): array {
        $description = implode(' ', $command);
        $process = ProcessHandle::start($command, $workingDirectory);
        $lastHeartbeatAt = microtime(true);

        try {
            while (!$process->settled()) {
                self::poll($process);
                $now = microtime(true);

                if ($process->age() >= $deadlineSeconds) {
                    throw new GateError(\sprintf(
                        'Timed out after %.1f seconds while running %s.',
                        $deadlineSeconds,
                        $description,
                    ));
                }

                if ($now - $lastHeartbeatAt >= self::HEARTBEAT_SECONDS) {
                    fwrite(\STDERR, \sprintf(
                        "finding-gate: still running %s (%ds).\n",
                        $description,
                        (int) $process->age(),
                    ));
                    $lastHeartbeatAt = $now;
                }
            }
        } catch (GateError $error) {
            $process->terminate();

            throw $error;
        }

        return $process->reap();
    }

    private static function poll(ProcessHandle $process): void
    {
        $read = $process->openStreams();

        if ($read !== []) {
            $write = $except = [];
            @stream_select($read, $write, $except, 0, 200_000);
        } else {
            usleep(200_000);
        }

        $process->drain();
    }
}
