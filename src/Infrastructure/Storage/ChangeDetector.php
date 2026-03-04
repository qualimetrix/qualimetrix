<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Storage;

use RuntimeException;
use SplFileInfo;

/**
 * Detects file changes using content hash and quick checks.
 */
final class ChangeDetector
{
    /**
     * Computes content hash of a file.
     * Uses xxh3 for performance (faster than md5/sha256).
     */
    public function getContentHash(SplFileInfo $file): string
    {
        $hash = @hash_file('xxh3', $file->getPathname());

        if ($hash === false) {
            // Fallback to sha256 if xxh3 is not available
            $hash = hash_file('sha256', $file->getPathname());

            if ($hash === false) {
                throw new RuntimeException("Failed to compute hash for file: {$file->getPathname()}");
            }
        }

        return $hash;
    }

    /**
     * Quick check using mtime and size (avoids reading file content).
     * Returns true if file likely unchanged based on metadata.
     */
    public function quickCheck(SplFileInfo $file, int $cachedMtime, int $cachedSize): bool
    {
        return $file->getMTime() === $cachedMtime
            && $file->getSize() === $cachedSize;
    }
}
