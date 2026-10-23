<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use InvalidArgumentException;
use PhpParser\Node;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\Path\AbsolutePath;

/** @qmx-threshold coupling.instability 0.857144 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies. */
final class FileCache implements CacheInterface
{
    private readonly AstEntryCodec $codec;
    private int $discardedEntries = 0;

    public function __construct(private readonly AbsolutePath $directory)
    {
        $this->codec = new AstEntryCodec();
    }

    /** @return ?list<Node> */
    public function get(string $key): ?array
    {
        $path = $this->getPath($key);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        $nodes = $this->codec->decode($bytes);
        if ($nodes === null) {
            ++$this->discardedEntries;
            @unlink($path);

            return null;
        }
        @touch($path);

        return $nodes;
    }

    public function set(string $key, mixed $value): void
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('AST cache entries must be a list of nodes.');
        }
        foreach ($value as $node) {
            if (!$node instanceof Node) {
                throw new InvalidArgumentException('AST cache entries must be a list of nodes.');
            }
        }
        $path = $this->getPath($key);
        $bytes = $this->codec->encode($value);
        if ($bytes === null) {
            return;
        }
        try {
            CacheLayout::ensureDirectory(\dirname($path));
            CacheLayout::ensureVersion($this->directory->value());
            CacheLayout::write($path, $bytes);
        } catch (FileTargetFailure $failure) {
            throw new CacheWriteException(\sprintf('Failed to write cache file "%s": %s', $path, $failure->getMessage()), 0, $failure);
        }
    }

    public function has(string $key): bool
    {
        $path = $this->getPath($key);

        return is_file($path) && !is_link($path);
    }

    public function delete(string $key): void
    {
        @unlink($this->getPath($key));
    }

    public function clear(): CacheClearOutcome
    {
        return (new CacheDirectoryContents($this->directory))->clear();
    }

    public function sweepStep(int $now, SweepPolicy $policy): SweepStepOutcome
    {
        return (new CacheSweep($this->directory))->step($now, $policy);
    }

    public function discardedEntryCount(): int
    {
        return $this->discardedEntries;
    }

    public function getDirectory(): AbsolutePath
    {
        return $this->directory;
    }

    private function getPath(string $key): string
    {
        if (!CacheLayout::entryName($key)) {
            throw new InvalidArgumentException('An AST cache key must contain 64 lowercase hexadecimal characters.');
        }

        return CacheLayout::versionDirectory($this->directory->value()) . '/' . substr($key, 0, 2) . '/' . $key;
    }
}
