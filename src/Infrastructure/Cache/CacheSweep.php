<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\Path\AbsolutePath;
use Throwable;

final readonly class CacheSweep
{
    public function __construct(private AbsolutePath $directory) {}

    public function step(int $now, SweepPolicy $policy): SweepStepOutcome
    {
        $current = CacheLayout::versionDirectory($this->directory->value());
        $cursorPath = $current . '/.sweep';
        $raw = @file_get_contents($cursorPath);
        $cursor = $raw !== false && preg_match('/^(?:[0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/D', $raw) === 1 ? (int) $raw : random_int(0, 255);
        $removed = $bytes = $shards = 0;
        $versions = CacheLayout::versions($this->directory->value());
        if ($versions === null) {
            throw new CacheRootUntrusted('Cannot inspect cache version directories in "' . $this->directory->value() . '".');
        }
        foreach ($versions as $version) {
            for ($offset = 0; $offset < $policy->shardsPerStep; ++$offset) {
                $shard = $version . '/' . \sprintf('%02x', ($cursor + $offset) % 256);
                [$count, $size] = $this->sweepShard($shard, $now, $policy);
                $removed += $count;
                $bytes += $size;
                ++$shards;
            }
        }
        $next = ($cursor + $policy->shardsPerStep) % 256;
        $failure = null;
        try {
            CacheLayout::ensureVersion($this->directory->value());
            CacheLayout::write($cursorPath, (string) $next);
        } catch (Throwable $error) {
            $failure = $error->getMessage();
        }

        return new SweepStepOutcome($shards, $removed, $bytes, $next, $failure);
    }

    /** @return array{int, int} */
    private function sweepShard(string $directory, int $now, SweepPolicy $policy): array
    {
        if (!is_dir($directory) || is_link($directory)) {
            return [0, 0];
        }
        $entries = $this->entriesIn($directory);
        $total = array_sum(array_column($entries, 'size'));
        usort($entries, static fn(array $a, array $b): int => [$a['mtime'], $a['path']] <=> [$b['mtime'], $b['path']]);
        $newest = \count($entries) - 1;
        $removed = $bytes = 0;
        foreach ($entries as $index => $entry) {
            if ($entry['mtime'] >= $now - $policy->maxAgeSeconds && ($total <= $policy->shardQuotaBytes || $index === $newest)) {
                continue;
            }
            if (@unlink($entry['path'])) {
                ++$removed;
                $bytes += $entry['size'];
                $total -= $entry['size'];
            }
        }
        @rmdir($directory);

        return [$removed, $bytes];
    }
    /** @return list<array{path: string, mtime: int, size: int}> */
    private function entriesIn(string $directory): array
    {
        $entries = [];
        $names = @scandir($directory);
        foreach ($names === false ? [] : $names as $name) {
            $path = $directory . '/' . $name;
            if (!CacheLayout::entryName(basename($path)) || !is_file($path) || is_link($path)) {
                continue;
            }
            $mtime = filemtime($path);
            $size = filesize($path);
            if ($mtime === false || $size === false) {
                continue;
            }
            $entries[] = ['path' => $path, 'mtime' => $mtime, 'size' => $size];
        }
        return $entries;
    }

}
