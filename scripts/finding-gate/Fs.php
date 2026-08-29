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

    public static function write(string $path, string $contents): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new GateError(\sprintf('Cannot create directory %s.', $directory));
        }

        if (@file_put_contents($path, $contents) === false) {
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
