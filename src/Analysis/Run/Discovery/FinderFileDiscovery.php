<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use FilesystemIterator;
use Generator;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FinderFileDiscovery implements FileDiscoveryInterface
{
    private readonly DirectoryPruner $directoryPruner;

    public function __construct(?DirectoryPruner $directoryPruner = null)
    {
        $workingDirectory = getcwd();
        $root = AbsolutePath::fromString($workingDirectory !== false ? $workingDirectory : '/');
        $this->directoryPruner = $directoryPruner
            ?? new DirectoryPruner($root, DirectoryPruner::builtInPatterns());
    }

    public function discover(AbsolutePath|array $paths): iterable
    {
        $paths = $paths instanceof AbsolutePath ? [$paths] : $paths;

        if ($paths === []) {
            return;
        }

        /** @var list<AbsolutePath> $directories */
        $directories = [];
        /** @var list<AbsolutePath> $files */
        $files = [];

        foreach ($paths as $path) {
            if (!$path->exists()) {
                continue;
            }

            if ($path->isDirectory()) {
                $directories[] = $path;
            } elseif ($path->isFile() && pathinfo($path->value(), \PATHINFO_EXTENSION) === 'php') {
                $files[] = $path;
            }
        }

        usort($files, static fn(AbsolutePath $a, AbsolutePath $b): int => $a->value() <=> $b->value());

        // Tracks emitted file pathnames so overlapping inputs (e.g. `src/ src/sub/`,
        // or a single-file arg that also lives inside a directory arg) yield each
        // file exactly once. Pre-ADR-0015 this was handled implicitly by
        // iterator_to_array(..., true) collapsing duplicate string keys; with
        // AbsolutePath as the iterator key, dedup is now explicit at the source.
        $seen = [];

        foreach ($files as $file) {
            $key = $file->value();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            yield $file => new SplFileInfo($key);
        }

        if ($directories !== []) {
            yield from $this->discoverInDirectories($directories, $seen);
        }
    }

    /**
     * @param list<AbsolutePath> $directories
     * @param array<string, true> $seen Pathnames already yielded (mutated by reference).
     *
     * @return Generator<AbsolutePath, SplFileInfo>
     */
    private function discoverInDirectories(array $directories, array &$seen): Generator
    {
        $files = [];
        foreach ($directories as $directory) {
            if ($this->directoryPruner->match($directory) !== null) {
                continue;
            }

            $filter = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(
                    $directory->value(),
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS | FilesystemIterator::CURRENT_AS_FILEINFO,
                ),
                function (SplFileInfo $entry): bool {
                    if ($entry->isDir()) {
                        return $this->directoryPruner->match(AbsolutePath::fromString($entry->getPathname())) === null;
                    }

                    return $entry->isFile() && $entry->getExtension() === 'php';
                },
            );

            foreach (new RecursiveIteratorIterator($filter) as $file) {
                if ($file instanceof SplFileInfo) {
                    $files[$file->getPathname()] = $file;
                }
            }
        }
        ksort($files);

        foreach ($files as $file) {
            $pathname = $file->getPathname();
            if (isset($seen[$pathname])) {
                continue;
            }
            $seen[$pathname] = true;
            yield AbsolutePath::fromString($pathname) => $file;
        }
    }
}
