<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Util;

/**
 * Normalizes file paths to be relative to CWD.
 *
 * Ensures consistent canonical keys in baseline regardless of whether the user
 * passes absolute paths, ./src/, or src/ to the CLI.
 */
final class PathNormalizer
{
    public static function relativize(string $path): string
    {
        // Strip ./ prefix
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        // Relativize absolute paths against CWD
        $cwd = (string) getcwd();
        $prefix = rtrim($cwd, '/') . '/';
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, \strlen($prefix));
        }

        return $path;
    }
}
