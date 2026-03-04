<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Cache;

use RuntimeException;

/**
 * Exception thrown when cache write operations fail.
 */
final class CacheWriteException extends RuntimeException
{
    public static function failedToCreateDirectory(string $dir): self
    {
        return new self(\sprintf(
            'Failed to create cache directory: %s',
            $dir,
        ));
    }

    public static function failedToWriteFile(string $path): self
    {
        return new self(\sprintf(
            'Failed to write cache file: %s',
            $path,
        ));
    }

    public static function failedToRename(string $from, string $to): self
    {
        return new self(\sprintf(
            'Failed to rename cache file: %s -> %s',
            $from,
            $to,
        ));
    }
}
