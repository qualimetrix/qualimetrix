<?php

declare(strict_types=1);

namespace QmxFindingGate;

use Closure;
use Throwable;

/** Failure-only facts for recursive scratch-tree cleanup. */
final class RemovalFailureDiagnostics
{
    private const ENTRY_LIMIT = 32;
    private const PROCESS_LIMIT = 16;
    private const PROC_PID_LIMIT = 4096;
    private const NATIVE_ERROR_LIMIT = 512;
    private const PROCESS_DEADLINE_SECONDS = 2.0;

    /**
     * Captures the warning raised by this operation, without consulting stale
     * process-global error state after absence checks or diagnostics run.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return array{T, ?string}
     */
    public static function capture(Closure $operation): array
    {
        $nativeError = null;
        set_error_handler(static function (int $severity, string $message) use (&$nativeError): bool {
            $nativeError ??= self::oneLine($message, self::NATIVE_ERROR_LIMIT);

            return true;
        });

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $nativeError];
    }

    /**
     * @param (Closure(list<string>, string, float): array{stdout: string, stderr: string, exit: int})|null $processRunner
     */
    public static function message(
        string $base,
        string $root,
        ?string $nativeError,
        bool $operationFailed,
        ?Closure $processRunner = null,
    ): string {
        $errno = 'unknown';
        if ($nativeError !== null && preg_match('/\berrno(?:=|[ :]+)(\d+)\b/i', $nativeError, $matches) === 1) {
            $errno = $matches[1];
        }

        $native = $nativeError ?? ($operationFailed
            ? 'not reported by the PHP filesystem API'
            : 'operation reported success but the entry remained');

        return \sprintf(
            '%s [errno=%s; native error=%s; remaining entries=%s; live processes=%s]',
            $base,
            $errno,
            self::encode($native),
            self::diagnostic(static fn(): array => self::remainingEntries($root)),
            self::diagnostic(static fn(): array|string => self::liveProcesses($root, $processRunner)),
        );
    }

    /** @return list<string> */
    private static function remainingEntries(string $root): array
    {
        if (@is_link($root) || @is_file($root)) {
            return ['.'];
        }

        if (!@is_dir($root)) {
            return [];
        }

        /** @var list<array{string, string}> $pending */
        $pending = [[$root, '']];
        $found = [];
        $truncated = false;

        while ($pending !== [] && \count($found) < self::ENTRY_LIMIT) {
            [$directory, $prefix] = array_shift($pending);
            $entries = @scandir($directory);

            if ($entries === false) {
                $found[] = ($prefix === '' ? '.' : $prefix) . '/<unreadable>';
                continue;
            }

            sort($entries, \SORT_STRING);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
                $found[] = $relative;
                if (\count($found) >= self::ENTRY_LIMIT) {
                    $truncated = true;
                    break;
                }

                $absolute = $directory . '/' . $entry;
                if (!@is_link($absolute) && @is_dir($absolute)) {
                    $pending[] = [$absolute, $relative];
                }
            }
        }

        if ($truncated || $pending !== []) {
            if (\count($found) >= self::ENTRY_LIMIT) {
                array_pop($found);
            }
            $found[] = '<truncated>';
        }

        return $found;
    }

    /**
     * @param (Closure(list<string>, string, float): array{stdout: string, stderr: string, exit: int})|null $processRunner
     *
     * @return list<string>|string
     */
    private static function liveProcesses(string $root, ?Closure $processRunner): array|string
    {
        return match (\PHP_OS_FAMILY) {
            'Linux' => self::linuxProcesses($root),
            'Darwin' => self::darwinProcesses($root, $processRunner),
            default => 'unavailable on ' . \PHP_OS_FAMILY,
        };
    }

    /** @return list<string>|string */
    private static function linuxProcesses(string $root): array|string
    {
        [$entries, $nativeError] = self::capture(static fn(): array|false => scandir('/proc'));
        if ($entries === false) {
            return 'unavailable (cannot list /proc: '
                . ($nativeError ?? 'native error not reported')
                . ')';
        }

        $pids = [];
        $populationTruncated = false;
        foreach ($entries as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            if (\count($pids) >= self::PROC_PID_LIMIT) {
                $populationTruncated = true;
                break;
            }
            $pids[] = $entry;
        }
        sort($pids, \SORT_NUMERIC);
        $found = [];
        $matchingTruncated = false;
        $unreadableWorkingDirectories = 0;

        foreach ($pids as $pid) {
            $cwd = @readlink('/proc/' . $pid . '/cwd');
            if (!\is_string($cwd)) {
                ++$unreadableWorkingDirectories;
                continue;
            }
            if (!self::inside($cwd, $root)) {
                continue;
            }

            if (\count($found) >= self::PROCESS_LIMIT) {
                $matchingTruncated = true;
                break;
            }

            $command = @file_get_contents('/proc/' . $pid . '/comm');
            $found[] = \sprintf(
                'pid=%s command=%s cwd=%s',
                $pid,
                self::oneLine(\is_string($command) ? trim($command) : 'unknown', 80),
                $cwd,
            );
        }

        if ($matchingTruncated) {
            $found[] = '<truncated: more than 16 matching processes>';
        }
        if ($populationTruncated) {
            $found[] = '<truncated: process population exceeds 4096 inspected entries>';
        }
        if ($unreadableWorkingDirectories > 0) {
            $found[] = \sprintf(
                '<incomplete: cwd unavailable for %d inspected process %s>',
                $unreadableWorkingDirectories,
                $unreadableWorkingDirectories === 1 ? 'entry' : 'entries',
            );
        }

        return $found;
    }

    /**
     * @param (Closure(list<string>, string, float): array{stdout: string, stderr: string, exit: int})|null $processRunner
     *
     * @return list<string>|string
     */
    private static function darwinProcesses(string $root, ?Closure $processRunner): array|string
    {
        $workingDirectory = getcwd();
        $directory = $workingDirectory === false ? sys_get_temp_dir() : $workingDirectory;
        if ($processRunner === null) {
            if (!class_exists(Process::class)) {
                return 'unavailable (the supervised lsof runner is not loaded)';
            }
            $result = Process::run(
                ['lsof', '-a', '-d', 'cwd', '-Fpcn'],
                $directory,
                self::PROCESS_DEADLINE_SECONDS,
            );
        } else {
            $result = $processRunner(
                ['lsof', '-a', '-d', 'cwd', '-Fpcn'],
                $directory,
                self::PROCESS_DEADLINE_SECONDS,
            );
        }
        if ($result['exit'] !== 0) {
            return \sprintf(
                'unavailable (lsof exit %d: %s)',
                $result['exit'],
                self::oneLine($result['stderr'], 160),
            );
        }

        $pid = null;
        $command = 'unknown';
        $found = [];
        $lines = preg_split('/\r?\n/', $result['stdout']);
        foreach ($lines === false ? [] : $lines as $line) {
            $field = $line[0] ?? '';
            $value = substr($line, 1);
            if ($field === 'p') {
                $pid = ctype_digit($value) ? $value : null;
                $command = 'unknown';
            } elseif ($field === 'c') {
                $command = self::oneLine($value, 80);
            } elseif ($field === 'n' && $pid !== null && self::inside($value, $root)) {
                if (\count($found) >= self::PROCESS_LIMIT) {
                    $found[] = '<truncated: more than 16 matching processes>';
                    break;
                }
                $found[] = \sprintf('pid=%s command=%s cwd=%s', $pid, $command, $value);
            }
        }

        return $found;
    }

    private static function inside(string $path, string $root): bool
    {
        $canonicalRoot = @realpath($root);
        $boundary = rtrim($canonicalRoot === false ? $root : $canonicalRoot, '/');

        return $path === $boundary || str_starts_with($path, $boundary . '/');
    }

    /** @param Closure(): (list<string>|string) $read */
    private static function diagnostic(Closure $read): string
    {
        try {
            return self::encode($read());
        } catch (Throwable $error) {
            return self::encode('unavailable (' . self::oneLine($error->getMessage(), 160) . ')');
        }
    }

    private static function encode(mixed $value): string
    {
        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '"<unavailable>"' : $encoded;
    }

    private static function oneLine(string $value, int $limit): string
    {
        $singleLine = preg_replace('/\s+/', ' ', trim($value));
        $singleLine = $singleLine === null ? '' : $singleLine;

        return \strlen($singleLine) <= $limit ? $singleLine : substr($singleLine, 0, $limit) . '...';
    }
}
