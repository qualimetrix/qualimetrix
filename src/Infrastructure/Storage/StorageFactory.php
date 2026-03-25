<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Storage;

/**
 * Factory for creating storage instances based on project size and configuration.
 */
final class StorageFactory
{
    private const SQLITE_THRESHOLD = 1000;

    /**
     * Creates optimal storage based on file count and configuration.
     *
     * @param int $fileCount Estimated number of files to analyze
     * @param string|null $configuredType Explicit type: 'sqlite', 'memory', or null for auto
     * @param string $cacheDir Cache directory path
     */
    public function create(
        int $fileCount,
        ?string $configuredType = null,
        string $cacheDir = '.qmx-cache',
    ): StorageInterface {
        // Explicit configuration
        if ($configuredType === 'sqlite') {
            return new SqliteStorage("{$cacheDir}/metrics.db");
        }

        if ($configuredType === 'memory') {
            return new InMemoryStorage();
        }

        // Auto-detect: SQLite for projects > 1000 files
        if ($fileCount > self::SQLITE_THRESHOLD) {
            return new SqliteStorage("{$cacheDir}/metrics.db");
        }

        return new InMemoryStorage();
    }
}
