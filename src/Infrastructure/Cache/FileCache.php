<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Cache;

use AiMessDetector\Infrastructure\Serializer\SerializerInterface;
use AiMessDetector\Infrastructure\Serializer\SerializerSelector;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * File-based cache implementation with sharding and atomic writes.
 *
 * Automatically selects the best available serializer:
 * - igbinary (if ext-igbinary is installed) — faster and smaller
 * - PHP serialize (fallback) — always available
 */
final class FileCache implements CacheInterface
{
    private const EXTENSION = '.cache';

    private readonly SerializerInterface $serializer;

    public function __construct(
        private readonly string $directory,
        ?SerializerInterface $serializer = null,
    ) {
        $this->serializer = $serializer ?? SerializerSelector::createDefault()->select();
    }

    public function get(string $key): mixed
    {
        $path = $this->getPath($key);

        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            return null;
        }

        try {
            return $this->serializer->unserialize($content);
        } catch (Throwable) {
            // Corrupted cache entry - delete it
            @unlink($path);

            return null;
        }
    }

    /**
     * @throws CacheWriteException if write operation fails
     */
    public function set(string $key, mixed $value): void
    {
        $path = $this->getPath($key);
        $dir = \dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw CacheWriteException::failedToCreateDirectory($dir);
        }

        // Atomic write: write to temp file, then rename
        $tmp = $path . '.tmp.' . getmypid();

        if (@file_put_contents($tmp, $this->serializer->serialize($value)) === false) {
            throw CacheWriteException::failedToWriteFile($tmp);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw CacheWriteException::failedToRename($tmp, $path);
        }
    }

    public function has(string $key): bool
    {
        return is_file($this->getPath($key));
    }

    public function delete(string $key): void
    {
        $path = $this->getPath($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function clear(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    /**
     * Get the cache directory.
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Get path for a cache key with sharding.
     * Uses first 2 characters of key as subdirectory.
     */
    private function getPath(string $key): string
    {
        $shard = substr($key, 0, 2);

        return $this->directory . '/' . $shard . '/' . $key . self::EXTENSION;
    }
}
