<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use PhpParser\PhpVersion;
use SplFileInfo;

/**
 * Generates cache keys for PHP files based on their content and environment.
 */
final class CacheKeyGenerator
{
    private readonly string $cacheVersion;

    public function __construct()
    {
        // Cache version based on PHP version and php-parser version
        $this->cacheVersion = \sprintf(
            'php%s-parser%s',
            \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION,
            $this->getPhpParserVersion(),
        );
    }

    /**
     * Generate a unique cache key for a file.
     *
     * Key components:
     * - realpath: absolute path to file
     * - mtime: modification time
     * - size: file size
     * - cacheVersion: PHP + php-parser version
     */
    public function generate(SplFileInfo $file): string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            // File doesn't exist: use pathname with prefix and skip stat-dependent fields
            $data = \sprintf(
                'unresolved:%s|%s',
                $file->getPathname(),
                $this->cacheVersion,
            );

            return hash('xxh128', $data);
        }

        $data = \sprintf(
            '%s|%d|%d|%s',
            $realPath,
            $file->getMTime(),
            $file->getSize(),
            $this->cacheVersion,
        );

        return hash('xxh128', $data);
    }

    /**
     * Get the cache version string.
     */
    public function getCacheVersion(): string
    {
        return $this->cacheVersion;
    }

    private function getPhpParserVersion(): string
    {
        // Try to get exact version from Composer's installed.php
        $installedPath = __DIR__ . '/../../../vendor/composer/installed.php';

        if (file_exists($installedPath)) {
            /** @var array{versions: array<string, array{version?: string}>} $installed */
            $installed = require $installedPath;
            $version = $installed['versions']['nikic/php-parser']['version'] ?? null;

            if ($version !== null) {
                return $version;
            }
        }

        // Fallback: use PhpVersion class existence as indicator of php-parser 5.x
        if (class_exists(PhpVersion::class)) {
            return '5.x';
        }

        return '4.x';
    }
}
