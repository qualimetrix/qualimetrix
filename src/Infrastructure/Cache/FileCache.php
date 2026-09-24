<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use FilesystemIterator;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Serializer\SerializerInterface;
use Qualimetrix\Infrastructure\Serializer\SerializerSelector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use UnexpectedValueException;

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

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw CacheWriteException::failedToCreateDirectory($dir);
        }

        // Atomic write: write to temp file, then rename
        $tmp = self::temporaryPathFor($path);

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
        $this->serializerVerified = false;

        $this->removeEverything();
    }

    /**
     * Empties the directory and says whether it ended up empty.
     *
     * Three ways it may not: the directory itself refuses to open, a
     * subdirectory refuses to open, or an unlink or rmdir is refused. All
     * three leave entries behind in whatever format wrote them, and the caller
     * that clears in order to change format has to know.
     *
     * The answer is a second look rather than the walk's own tally, because
     * the walk cannot see the middle case: measured, `CATCH_GET_CHILD` drops
     * an unreadable subtree entirely — not even the directory itself is
     * yielded — so every removal can succeed over a directory that is not
     * empty. What the marker claims is that the directory holds nothing, and
     * that is what is checked.
     *
     * The marker is kept back from the walk and removed last, only once
     * everything else is gone. A marker deleted beside a surviving entry is
     * worse than one left in place: the next process reads "nothing says",
     * takes the no-clear branch and writes its own name over the old format.
     */
    private function removeEverything(): bool
    {
        $directory = $this->directory->value();

        if (!is_dir($directory)) {
            return true;
        }

        // A cache directory the process cannot descend into must cost the
        // clear, not the run: this runs from get() and set(), so an escaping
        // exception would turn a cache problem into a failed file.
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
        } catch (UnexpectedValueException) {
            return false;
        }

        $markerPath = $this->serializerMarkerPath();

        foreach ($iterator as $item) {
            if ($item->getPathname() === $markerPath) {
                continue;
            }

            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        if (!$this->holdsNothingButTheMarker()) {
            return false;
        }

        return !is_file($markerPath) || @unlink($markerPath);
    }

    /** Whether the walk left the directory with nothing in it but the marker. */
    private function holdsNothingButTheMarker(): bool
    {
        try {
            $entries = new FilesystemIterator(
                $this->directory->value(),
                FilesystemIterator::SKIP_DOTS
                | FilesystemIterator::KEY_AS_FILENAME
                | FilesystemIterator::CURRENT_AS_PATHNAME,
            );

            foreach ($entries as $filename => $_) {
                if ($filename !== self::SERIALIZER_MARKER) {
                    return false;
                }
            }
        } catch (UnexpectedValueException) {
            // A directory that cannot even be listed has certainly not been emptied.
            return false;
        }

        return true;
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
     * The marker is a claim about what the directory holds, so it is written
     * only once the directory holds nothing. A clear that could not finish
     * leaves the old marker in place and no new one: the next process reads a
     * mismatch again and tries again, which is the honest answer and not the
     * convenient one. Entries surviving in the old format then read back as
     * corrupt and are dropped by {@see get()}, so the cost is cache misses.
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

        if ($storedName !== null && !$this->removeEverything()) {
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

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
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
