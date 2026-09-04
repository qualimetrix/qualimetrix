<?php

declare(strict_types=1);

namespace QmxFindingGate;

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
     * The temporary file and the rename are not about crash safety here: the
     * controls harness clones the working tree by **hardlinking** its content,
     * so a scratch tree's `finding-gate/declared-delta.tsv` and the developer's
     * are one inode. `file_put_contents` truncates that inode in place, so a
     * run inside a scratch clone that writes the tracked declaration writes it
     * into the repository the developer is looking at. Measured on 2026-09-04:
     * one control run left this repository's index holding thirteen rows
     * derived from a mutated clone. The rename replaces the directory entry and
     * leaves the shared inode alone.
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
        // every concurrent writer would collide on `<path>.tmp.`. The controls
        // harness runs eight gates at once against clones of one tree.
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
     * A link is unlinked, never walked.
     *
     * `is_dir()` follows symlinks, so a link to a directory would be descended
     * into and its *target's* contents deleted. This function removes reference
     * worktrees and scratch directories, so following one link would delete
     * outside the tree it was asked to remove.
     */
    public static function removeRecursively(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            throw new GateError(\sprintf('Cannot list %s while removing it.', $path));
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::removeRecursively($path . '/' . $entry);
        }

        @rmdir($path);
    }

    public static function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));

        if (!@mkdir($path, 0o700, true)) {
            throw new GateError(\sprintf('Cannot create temporary directory %s.', $path));
        }

        return $path;
    }
}
