<?php

declare(strict_types=1);

namespace QmxFindingGate;

use Closure;

final class Fs
{
    public static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new GateError(\sprintf('Cannot read %s.', $path));
        }

        return $contents;
    }

    /**
     * Writes a whole file, and never through a hardlink.
     *
     * The temporary file and the rename replace the directory entry instead of
     * truncating the inode in place, which `file_put_contents` does: a file
     * **hardlinked** from another tree keeps its own content, so a run that
     * writes a declaration in one tree cannot write it into the other.
     *
     * A process killed between the write and the rename leaves the temporary
     * behind. It is not cleaned up here — there is nothing left running to do it
     * — but it is named so that it cannot be mistaken for a declaration and
     * cannot collide with another writer's.
     */
    public static function write(string $path, string $contents): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new GateError(\sprintf('Cannot create directory %s.', $directory));
        }

        // Random rather than the pid: `getmypid()` is `int|false`, and on false
        // every concurrent writer would collide on `<path>.tmp.`.
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));

        if (@file_put_contents($temporary, $contents) === false) {
            throw new GateError(\sprintf('Cannot write %s.', $temporary));
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new GateError(\sprintf('Cannot write %s.', $path));
        }
    }

    /**
     * A link observed during entry classification is unlinked, not walked.
     *
     * `is_dir()` follows symlinks, so a link to a directory would be descended
     * into and its *target's* contents deleted. This function removes reference
     * worktrees and scratch directories, so following one link would delete
     * outside the tree it was asked to remove. Cleanup tolerates concurrent
     * disappearance, but assumes entries are not concurrently substituted or
     * created: path-based classification and removal are not atomic.
     *
     * @param (Closure(string, string, ?string, bool): never)|null $refuse
     */
    public static function removeRecursively(string $path, ?Closure $refuse = null): void
    {
        $refuse ??= static function (string $message, string $root, ?string $nativeError, bool $operationFailed): never {
            throw new GateError(RemovalFailureDiagnostics::message(
                $message,
                $root,
                $nativeError,
                $operationFailed,
            ));
        };

        self::removeEntry($path, $path, $refuse);
    }

    /** @param Closure(string, string, ?string, bool): never $refuse */
    private static function removeEntry(string $path, string $root, Closure $refuse): void
    {
        if (@is_link($path)) {
            [$removed, $nativeError] = RemovalFailureDiagnostics::capture(static fn(): bool => @unlink($path));
            if (!$removed && !self::entryIsAbsent($path)) {
                $refuse(
                    \sprintf('Cannot unlink %s while removing it.', $path),
                    $root,
                    $nativeError,
                    true,
                );
            }

            if (!self::entryIsAbsent($path)) {
                $refuse(
                    \sprintf('Cannot unlink %s while removing it.', $path),
                    $root,
                    $nativeError,
                    !$removed,
                );
            }

            return;
        }

        if (!@is_dir($path)) {
            if (self::entryIsAbsent($path)) {
                return;
            }

            [$removed, $nativeError] = RemovalFailureDiagnostics::capture(static fn(): bool => @unlink($path));
            if (!$removed && !self::entryIsAbsent($path)) {
                $refuse(
                    \sprintf('Cannot unlink %s while removing it.', $path),
                    $root,
                    $nativeError,
                    true,
                );
            }

            if (!self::entryIsAbsent($path)) {
                $refuse(
                    \sprintf('Cannot unlink %s while removing it.', $path),
                    $root,
                    $nativeError,
                    !$removed,
                );
            }

            return;
        }

        [$entries, $nativeError] = RemovalFailureDiagnostics::capture(static fn(): array|false => @scandir($path));

        if ($entries === false) {
            if (self::entryIsAbsent($path)) {
                return;
            }

            $refuse(
                \sprintf('Cannot list %s while removing it.', $path),
                $root,
                $nativeError,
                true,
            );
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::removeEntry($path . '/' . $entry, $root, $refuse);
        }

        [$removed, $nativeError] = RemovalFailureDiagnostics::capture(static fn(): bool => @rmdir($path));
        if (!$removed && !self::entryIsAbsent($path)) {
            $refuse(
                \sprintf('Cannot remove directory %s.', $path),
                $root,
                $nativeError,
                true,
            );
        }

        if (!self::entryIsAbsent($path)) {
            $refuse(
                \sprintf('Cannot remove directory %s.', $path),
                $root,
                $nativeError,
                !$removed,
            );
        }
    }

    /**
     * A failed stat is ambiguous when a parent denies traversal. A readable
     * ancestor proves that the entry or one of its parents is absent.
     *
     * @phpstan-impure
     */
    private static function entryIsAbsent(string $path): bool
    {
        clearstatcache(true, $path);

        if (@lstat($path) !== false) {
            return false;
        }

        $entries = @scandir(\dirname($path));

        if ($entries !== false) {
            return !\in_array(basename($path), $entries, true);
        }

        $parent = \dirname($path);

        return $parent !== $path && self::entryIsAbsent($parent);
    }

    /**
     * Every scratch directory the gate makes, made in the one place that also
     * releases it. {@see Scratch::directory()} says why acquiring is what arms
     * the release.
     */
    public static function temporaryDirectory(string $prefix): string
    {
        return Scratch::directory($prefix);
    }
}
