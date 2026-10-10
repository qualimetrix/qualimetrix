<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\FileTarget\FileReplacement;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Serializer\SerializerInterface;
use Qualimetrix\Infrastructure\Serializer\SerializerSelector;
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
    private const SERIALIZER_MARKER = '.serializer';

    private readonly SerializerInterface $serializer;
    private bool $serializerVerified = false;

    public function __construct(
        private readonly AbsolutePath $directory,
        ?SerializerInterface $serializer = null,
    ) {
        $this->serializer = $serializer ?? SerializerSelector::createDefault()->select();
    }

    public function get(string $key): mixed
    {
        $this->ensureSerializerCompatibility();

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
        $this->ensureSerializerCompatibility();
        $path = $this->getPath($key);
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            if (@mkdir($dir, 0755, true)) {
                TargetPath::rememberCreatedDirectory($dir);
            } elseif (!is_dir($dir)) {
                throw CacheWriteException::failedToCreateDirectory($dir);
            }
        }

        try {
            FileReplacement::replace(
                TargetPath::resolve($path),
                $this->serializer->serialize($value),
                null,
                NewName::LastWriterWins,
            );
        } catch (FileTargetFailure $failure) {
            throw new CacheWriteException(\sprintf('Failed to write cache file "%s": %s', $path, $failure->getMessage()), 0, $failure);
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

    public function clear(): CacheClearOutcome
    {
        $this->serializerVerified = false;

        return $this->removeEverything();
    }

    private function removeEverything(): CacheClearOutcome
    {
        return (new CacheDirectoryContents(
            $this->directory->value(),
            $this->serializerMarkerPath(),
            self::EXTENSION,
        ))->clear();
    }

    /**
     * Get the cache directory.
     */
    public function getDirectory(): AbsolutePath
    {
        return $this->directory;
    }

    /**
     * Checks if the current serializer matches the one used to write the cache.
     * If not, clears the entire cache and writes a new marker.
     *
     * The marker is a claim about the cache entries' format. A clear that
     * leaves an owned entry or an uninspectable subtree keeps the old marker:
     * the next process reads the mismatch and tries again. Surviving entries
     * in the old format then read as corrupt and cost cache misses.
     *
     * The verified flag is set once per instance either way — a failed clear
     * is not worth a full directory walk on every subsequent read.
     */
    private function ensureSerializerCompatibility(): void
    {
        if ($this->serializerVerified) {
            return;
        }

        $this->serializerVerified = true;
        $currentName = $this->serializer->getName();
        $storedName = $this->storedSerializerName();

        if ($storedName === $currentName) {
            return;
        }

        if ($storedName !== null && !$this->removeEverything()->complete) {
            return;
        }

        $this->writeSerializerMarker($currentName);
    }

    /** The name the cache was written with, or null when nothing says. */
    private function storedSerializerName(): ?string
    {
        $storedName = @file_get_contents($this->serializerMarkerPath());

        return $storedName === false ? null : trim($storedName);
    }

    private function writeSerializerMarker(string $name): void
    {
        $directory = $this->directory->value();

        if (!is_dir($directory)) {
            if (@mkdir($directory, 0755, true)) {
                TargetPath::rememberCreatedDirectory($directory);
            } elseif (!is_dir($directory)) {
                return;
            }
        }

        // Same discipline as the entries themselves. A marker torn by a
        // concurrent write reads as one more mismatch, and every process that
        // reads it that way clears the whole directory again — including the
        // entries its siblings have just written.
        $markerPath = $this->serializerMarkerPath();
        $tmp = self::temporaryPathFor($markerPath);

        if (@file_put_contents($tmp, $name) === false) {
            return;
        }

        if (!@rename($tmp, $markerPath)) {
            @unlink($tmp);
        }
    }

    private function serializerMarkerPath(): string
    {
        return $this->directory->value() . '/' . self::SERIALIZER_MARKER;
    }

    /**
     * A temporary name that no concurrent writer can be using.
     *
     * Not the process id: a worker is a process today, but the parallel
     * transport this cache is declared safe for admits threads, and two
     * threads writing the same key would agree on a pid and disagree on bytes.
     */
    private static function temporaryPathFor(string $path): string
    {
        return $path . '.tmp.' . bin2hex(random_bytes(8));
    }

    /**
     * Get path for a cache key with sharding.
     * Uses first 2 characters of key as subdirectory.
     */
    private function getPath(string $key): string
    {
        $shard = substr($key, 0, 2);

        return $this->directory->value() . '/' . $shard . '/' . $key . self::EXTENSION;
    }
}
