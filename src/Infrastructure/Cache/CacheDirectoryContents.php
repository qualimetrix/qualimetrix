<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\FileTarget\TemporarySibling;
use Qualimetrix\Core\Path\AbsolutePath;

final readonly class CacheDirectoryContents
{
    public function __construct(private AbsolutePath $directory) {}

    public function clear(): CacheClearOutcome
    {
        $remaining = 0;
        $reason = null;
        $retained = [];
        $detachedDirectories = glob($this->directory->value() . '/.clearing-*');
        foreach ($detachedDirectories === false ? [] : $detachedDirectories as $detached) {
            if (preg_match('/^\.clearing-[0-9a-f]{16}$/D', basename($detached)) !== 1 || is_link($detached)) {
                continue;
            }
            [$count, $failure, $retainedDirectory] = $this->clearVersion($detached);
            $remaining += $count;
            $reason ??= $failure;
            if ($retainedDirectory !== null) {
                $retained[] = $retainedDirectory;
            }
        }
        $versions = CacheLayout::versions($this->directory->value());
        if ($versions === null) {
            return new CacheClearOutcome(false, 0, $this->directory->value(), 'Cannot inspect cache version directories.');
        }
        foreach ($versions as $version) {
            [$count, $failure, $retainedDirectory] = $this->detachAndClear($version);
            if ($retainedDirectory !== null) {
                $retained[] = $retainedDirectory;
            }
            $remaining += $count;
            $reason ??= $failure;
        }

        return new CacheClearOutcome($remaining === 0 && $reason === null && $retained === [], $remaining, $this->directory->value(), $reason ?? ($retained === [] ? null : 'Retained detached cache directories: ' . implode(', ', $retained)));
    }

    /** @return array{int, ?string, ?string} */
    private function detachAndClear(string $version): array
    {
        $detached = $this->directory->value() . '/.clearing-' . bin2hex(random_bytes(8));
        if (!@rename($version, $detached)) {
            return [1, 'Cannot detach cache version directory: ' . $version, null];
        }

        return $this->clearVersion($detached);
    }

    /** @return array{int, ?string, ?string} */
    private function clearVersion(string $directory): array
    {
        $names = @scandir($directory);
        if ($names === false) {
            return [0, 'Cannot inspect detached cache directory: ' . $directory, null];
        }
        foreach ($names as $name) {
            $this->clearItem($directory . '/' . $name, $name);
        }
        [$remaining, $failure] = $this->inspectResidue($directory);
        @rmdir($directory);

        return [$remaining, $failure, is_dir($directory) ? $directory : null];
    }

    /** @return array{int, ?string} */
    private function inspectResidue(string $directory): array
    {
        $names = @scandir($directory);
        if ($names === false) {
            return [0, 'Cannot inspect detached cache directory: ' . $directory];
        }
        $remaining = 0;
        $failure = null;
        foreach ($names as $name) {
            [$count, $reason] = $this->inspectItem($directory . '/' . $name, $name);
            $remaining += $count;
            $failure ??= $reason;
        }

        return [$remaining, $failure];
    }

    /** @return array{int, ?string} */
    private function inspectItem(string $path, string $name): array
    {
        if (self::metadataName($name)) {
            return [0, 'Cannot remove cache metadata: ' . $path];
        }
        if (preg_match('/^[0-9a-f]{2}$/D', $name) !== 1) {
            return [0, null];
        }
        if (is_link($path)) {
            return [0, 'Cannot inspect linked cache shard: ' . $path];
        }
        if (!is_dir($path)) {
            return [0, null];
        }
        $names = @scandir($path);
        if ($names === false) {
            return [0, 'Cannot inspect cache shard: ' . $path];
        }
        $remaining = \count(array_filter($names, self::ownedEntryName(...)));

        return [$remaining, $remaining === 0 ? null : 'Cannot remove cache entries in: ' . $path];
    }

    private static function metadataName(string $name): bool
    {
        return $name === 'CACHEDIR.TAG' || $name === '.sweep' || TemporarySibling::ownsName($name);
    }

    private static function ownedEntryName(string $name): bool
    {
        return CacheLayout::entryName($name) || TemporarySibling::ownsName($name);
    }

    /** @return array{int, ?string} */
    private function clearItem(string $path, string $name): array
    {
        if (self::metadataName($name)) {
            return @unlink($path) ? [0, null] : [0, 'Cannot remove cache metadata: ' . $path];
        }
        if (preg_match('/^[0-9a-f]{2}$/D', $name) === 1 && is_dir($path) && !is_link($path)) {
            return $this->clearShard($path);
        }

        return [0, null];
    }

    /** @return array{int, ?string} */
    private function clearShard(string $directory): array
    {
        $names = @scandir($directory);
        if ($names === false) {
            return [1, 'Cannot inspect cache shard: ' . $directory];
        }
        $remaining = 0;
        foreach ($names as $name) {
            if (self::ownedEntryName($name)) {
                if (!@unlink($directory . '/' . $name)) {
                    ++$remaining;
                }
            }
        }
        @rmdir($directory);

        return [$remaining, $remaining === 0 ? null : 'Cannot remove cache entries in: ' . $directory];
    }
}
