<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

/** Runs one bounded per-source NSS enumeration without a shell. */
final class NativeNssEnumerator
{
    private const int MAX_ENUMERATION_BYTES = 4_194_304;
    private const float ENUMERATION_SECONDS = 2.0;
    private const string GETENT = '/usr/bin/getent';

    /** @return ?array{exitCode: int, output: string} */
    public static function enumerate(string $source, string $database): ?array
    {
        if (!is_executable(self::GETENT) || !\function_exists('proc_open')) {
            return null;
        }
        $opened = self::start($source, $database);
        if ($opened === null) {
            return null;
        }

        [$process, $stream] = $opened;

        return self::drain($process, $stream);
    }

    /** @return ?array{resource, resource} */
    private static function start(string $source, string $database): ?array
    {
        $pipes = [];
        [$process] = NativeCall::attempt(static function () use (&$pipes, $source, $database) {
            return proc_open(
                [self::GETENT, '-s', $source, $database],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
        });
        if (!\is_resource($process)) {
            return null;
        }
        if (!isset($pipes[1]) || !\is_resource($pipes[1])) {
            proc_terminate($process, 9);
            proc_close($process);

            return null;
        }

        return [$process, $pipes[1]];
    }

    /**
     * @param resource $process
     * @param resource $stream
     *
     * @return ?array{exitCode: int, output: string}
     */
    private static function drain(mixed $process, mixed $stream): ?array
    {
        $deadline = microtime(true) + self::ENUMERATION_SECONDS;
        $output = '';
        $exitCode = -1;
        $observedExitCode = null;
        try {
            if (!stream_set_blocking($stream, false)) {
                return null;
            }
            do {
                if (!self::appendAvailable($stream, $output)) {
                    return null;
                }
                $completed = self::completedExitCode($process, $stream, $observedExitCode);
                if ($completed !== null) {
                    $exitCode = $completed;
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);

            if ($exitCode < 0) {
                return null;
            }

            return ['exitCode' => $exitCode, 'output' => $output];
        } finally {
            if ($exitCode < 0) {
                proc_terminate($process, 9);
            }
            fclose($stream);
            proc_close($process);
        }
    }

    /** @param resource $stream */
    private static function appendAvailable(mixed $stream, string &$output): bool
    {
        $chunk = fread($stream, 8192);
        if ($chunk === false) {
            return false;
        }
        $output .= $chunk;

        return \strlen($output) <= self::MAX_ENUMERATION_BYTES;
    }

    /**
     * @param resource $process
     * @param resource $stream
     */
    private static function completedExitCode(mixed $process, mixed $stream, ?int &$observedExitCode): ?int
    {
        $status = proc_get_status($process);
        if (!$status['running'] && $observedExitCode === null) {
            $observedExitCode = $status['exitcode'];
        }

        return !$status['running'] && feof($stream) ? $observedExitCode ?? -1 : null;
    }
}
