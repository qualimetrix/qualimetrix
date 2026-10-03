<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

final readonly class CacheDirectoryContents
{
    public function __construct(
        private string $directory,
        private string $markerPath,
        private string $entryExtension,
    ) {}

    public function clear(): CacheClearOutcome
    {
        if (!is_dir($this->directory)) {
            return $this->missingDirectoryOutcome();
        }

        $walkFailure = $this->removeNonMarkerEntries();
        [$remaining, $inspectionFailure] = $this->inspectResidue();
        if ($remaining > 0 || $inspectionFailure !== null || $walkFailure !== null) {
            return new CacheClearOutcome(
                false,
                $remaining,
                $this->directory,
                $inspectionFailure ?? $walkFailure ?? \sprintf('%d cache entries remain after clear.', $remaining),
            );
        }

        $markerFailure = $this->removeMarker();

        return new CacheClearOutcome($markerFailure === null, 0, $this->directory, $markerFailure);
    }

    private function missingDirectoryOutcome(): CacheClearOutcome
    {
        return (file_exists($this->directory) || is_link($this->directory))
            ? new CacheClearOutcome(false, 0, $this->directory, 'Cache directory is not a directory.')
            : new CacheClearOutcome(true, 0, $this->directory, null);
    }

    private function removeNonMarkerEntries(): ?string
    {
        // CATCH_GET_CHILD can skip a closed subtree; inspectResidue must verify the result.
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($iterator as $item) {
                if ($item->getPathname() === $this->markerPath) {
                    continue;
                }

                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        } catch (UnexpectedValueException $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    /** @return array{int, ?string} */
    private function inspectResidue(): array
    {
        $remaining = 0;
        try {
            [$linkedShard, $rootFailure] = $this->linkedShard();
            if ($rootFailure !== null) {
                return [$remaining, $rootFailure];
            }

            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($entries as $entry) {
                if ($this->isOwnedEntry($entry->getPathname())) {
                    ++$remaining;
                }
            }
        } catch (UnexpectedValueException $failure) {
            return [$remaining, 'Cannot inspect all remaining cache entries: ' . $failure->getMessage()];
        }

        return [$remaining, $linkedShard === null ? null : 'Cannot inspect linked cache shard: ' . $linkedShard];
    }

    /** @return array{?string, ?string} */
    private function linkedShard(): array
    {
        $linkedShard = null;
        foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $rootEntry) {
            if (!$rootEntry instanceof SplFileInfo) {
                return [null, 'Cannot inspect a cache directory entry.'];
            }
            $name = $rootEntry->getFilename();
            if ($rootEntry->isLink() && \strlen($name) >= 1 && \strlen($name) <= 2) {
                $linkedShard = $rootEntry->getPathname();
            }
        }

        return [$linkedShard, null];
    }

    private function isOwnedEntry(string $path): bool
    {
        $relative = substr($path, \strlen($this->directory) + 1);
        $parts = explode('/', $relative);
        if (\count($parts) !== 2) {
            return false;
        }

        return \strlen($parts[0]) >= 1 && \strlen($parts[0]) <= 2
            && str_starts_with($parts[1], $parts[0]) && str_ends_with($parts[1], $this->entryExtension);
    }

    private function removeMarker(): ?string
    {
        if ((file_exists($this->markerPath) || is_link($this->markerPath)) && !@unlink($this->markerPath)) {
            return 'Cannot remove the serializer marker.';
        }

        return null;
    }
}
