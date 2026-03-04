<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Discovery;

use Generator;
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

    public function discover(string|array $paths): iterable
    {
        $paths = \is_string($paths) ? [$paths] : $paths;

        if ($paths === []) {
            return;
        }

        $directories = [];
        $files = [];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            if (is_dir($path)) {
                $directories[] = $path;
            } elseif (is_file($path) && pathinfo($path, \PATHINFO_EXTENSION) === 'php') {
                $files[] = $path;
            }
        }

        // Yield individual files first (sorted), using path as key to avoid conflicts
        sort($files);
        foreach ($files as $file) {
            yield $file => new SplFileInfo($file);
        }

        // Then yield files from directories
        if ($directories !== []) {
            yield from $this->discoverInDirectories($directories);
        }
    }

    /**
     * @param list<string> $directories
     *
     * @return Generator<string, SplFileInfo>
     */
    private function discoverInDirectories(array $directories): Generator
    {
        $finder = new Finder();
        $finder
            ->files()
            ->name('*.php')
            ->in($directories)
            ->exclude($this->excludedDirs)
            ->sortByName();

        foreach ($finder as $file) {
            yield $file->getPathname() => $file;
        }
    }
}
