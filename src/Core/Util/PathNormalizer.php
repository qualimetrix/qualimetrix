<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

/**
 * Normalizes file paths to be relative to the project root.
 *
 * Ensures consistent canonical keys in baseline regardless of whether the user
 * passes absolute paths, ./src/, or src/ to the CLI.
 */
final class PathNormalizer
{
    /**
     * @param string $path Path to relativize
     * @param string|null $projectRoot Base directory (defaults to getcwd(), which is the project root
     *                                 after Application::doRun() applies --working-dir)
     */
    public static function relativize(string $path, ?string $projectRoot = null): string
    {
        // Strip ./ prefix
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        // Relativize absolute paths against project root
        $base = $projectRoot ?? (string) getcwd();
        $prefix = rtrim($base, '/') . '/';
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, \strlen($prefix));
        }

        return $path;
    }
}
