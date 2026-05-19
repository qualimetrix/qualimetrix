<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Discovery;

use Generator;
use Qualimetrix\Core\Path\AbsolutePath;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

final class FinderFileDiscovery implements FileDiscoveryInterface
{
    /**
     * @param list<string> $excludedDirs directories to exclude
     */
    public function __construct(
        private readonly array $excludedDirs = ['vendor', 'node_modules', '.git'],
    ) {}

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
        $finder = new Finder();
        $finder
            ->files()
            ->name('*.php')
            ->in(array_map(static fn(AbsolutePath $p): string => $p->value(), $directories))
            ->exclude($this->excludedDirs)
            ->sortByName();

        foreach ($finder as $file) {
            $pathname = $file->getPathname();
            if (isset($seen[$pathname])) {
                continue;
            }
            $seen[$pathname] = true;
            yield AbsolutePath::fromString($pathname) => $file;
        }
    }
}
