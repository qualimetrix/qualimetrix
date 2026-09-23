<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Discovery;

use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;

final class ComposerReader implements ComposerAutoloadPathReaderInterface
{
    /**
     * Extracts paths from autoload.psr-4.
     *
     * Handles both single-path strings and multi-path arrays per PSR-4 spec:
     *   "App\\": "src/"
     *   "Lib\\": ["lib/", "packages/"]
     *
     * @return list<string> Paths relative to composer.json
     */
    public function extractAutoloadPaths(string $composerJsonPath): array
    {
        return $this->psr4Paths($composerJsonPath, 'autoload');
    }

    /** @return list<string> Paths relative to composer.json */
    public function extractAutoloadDevPaths(string $composerJsonPath): array
    {
        return $this->psr4Paths($composerJsonPath, 'autoload-dev');
    }

    /** @return list<string> */
    private function psr4Paths(string $composerJsonPath, string $section): array
    {
        $data = $this->decode($composerJsonPath);
        if ($data === null) {
            return [];
        }

        $roots = [];
        $this->collectPsr4Roots($data, $section, $roots);

        $paths = [];
        foreach ($roots as $prefixPaths) {
            foreach ($prefixPaths as $path) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * The whole PSR-4 map, `autoload-dev` included, read in one parse.
     *
     * No production-only variant: the map answers where a namespace lives,
     * and a caller placing a namespace has to see the test roots too — a
     * value naming test code is not homeless, it is served from
     * `autoload-dev`.
     *
     * @return array<string, list<string>>
     */
    public function extractPsr4Roots(string $composerJsonPath): array
    {
        $data = $this->decode($composerJsonPath);
        if ($data === null) {
            return [];
        }

        $roots = [];
        $this->collectPsr4Roots($data, 'autoload', $roots);
        $this->collectPsr4Roots($data, 'autoload-dev', $roots);

        return $roots;
    }

    /**
     * The production autoload targets, every section of it.
     *
     * Sections are read in the order `composer.json` writes them and merged
     * into one list, because the comparison downstream is about containment
     * and not about which mechanism served a path.
     *
     * @return ?list<string>
     */
    public function productionAutoloadTargets(string $composerJsonPath): ?array
    {
        return $this->sectionTargets($composerJsonPath, 'autoload');
    }

    /** @return ?list<string> */
    public function developmentAutoloadTargets(string $composerJsonPath): ?array
    {
        return $this->sectionTargets($composerJsonPath, 'autoload-dev');
    }

    /** @return ?list<string> */
    private function sectionTargets(string $composerJsonPath, string $section): ?array
    {
        $data = $this->decode($composerJsonPath);

        if (!\is_array($data[$section] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $autoload */
        $autoload = $data[$section];

        // Every section merged into one list: the comparison downstream is
        // about containment, not about which mechanism served a path.
        $targets = array_values(array_unique([
            ...$this->prefixMapPaths($autoload['psr-4'] ?? null),
            ...$this->prefixMapPaths($autoload['psr-0'] ?? null),
            ...$this->pathListPaths($autoload['classmap'] ?? null),
            ...$this->pathListPaths($autoload['files'] ?? null),
        ]));

        // Empty is not "declares nothing to compare against but is otherwise
        // fine": a manifest whose every production section is empty or
        // malformed declared no production code this product can see, which
        // is the same answer as having no section at all.
        return $targets === [] ? null : $targets;
    }

    /**
     * The paths of a prefix-keyed section — `psr-4` or `psr-0`. The prefix
     * names no path; the value is a path or a list of them.
     *
     * @return list<string>
     */
    private function prefixMapPaths(mixed $map): array
    {
        if (!\is_array($map)) {
            return [];
        }

        $paths = [];
        foreach ($map as $pathOrPaths) {
            foreach ($this->normalizePaths($pathOrPaths) as $normalized) {
                $paths[] = $normalized;
            }
        }

        return $paths;
    }

    /**
     * The paths of a plain list section — `classmap` or `files`. Each entry
     * may name a single file rather than a directory.
     *
     * @return list<string>
     */
    private function pathListPaths(mixed $list): array
    {
        if (!\is_array($list)) {
            return [];
        }

        $paths = [];
        foreach ($list as $path) {
            foreach ($this->normalizePaths($path) as $normalized) {
                $paths[] = $normalized;
            }
        }

        return $paths;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function decode(string $composerJsonPath): ?array
    {
        if (!file_exists($composerJsonPath)) {
            return null;
        }

        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return \is_array($data) ? $data : null;
    }

    /**
     * Collects normalized PSR-4 roots from the given autoload section.
     *
     * A prefix declared in both `autoload` and `autoload-dev` keeps both
     * directory lists: the two sections are additive at runtime, and dropping
     * either would hide a root from every caller asking where a namespace
     * lives.
     *
     * @param array<string, mixed> $data Decoded composer.json
     * @param string $section Either 'autoload' or 'autoload-dev'
     * @param array<string, list<string>> $roots Collected roots (modified by reference)
     */
    private function collectPsr4Roots(array $data, string $section, array &$roots): void
    {
        if (!isset($data[$section]['psr-4']) || !\is_array($data[$section]['psr-4'])) {
            return;
        }

        foreach ($data[$section]['psr-4'] as $prefix => $pathOrPaths) {
            if (!\is_string($prefix)) {
                continue;
            }

            foreach ($this->normalizePaths($pathOrPaths) as $normalized) {
                if ($normalized !== '' && !\in_array($normalized, $roots[$prefix] ?? [], true)) {
                    $roots[$prefix][] = $normalized;
                }
            }
        }
    }

    /**
     * Normalizes a PSR-4 path value: a string, or an array of them. Anything
     * else in that position is not a PSR-4 mapping and contributes no path.
     *
     * @return list<string>
     */
    private function normalizePaths(mixed $pathOrPaths): array
    {
        if (\is_string($pathOrPaths)) {
            return [(rtrim($pathOrPaths, '/') !== '' ? rtrim($pathOrPaths, '/') : '.')];
        }

        if (!\is_array($pathOrPaths)) {
            return [];
        }

        $result = [];
        foreach ($pathOrPaths as $path) {
            if (\is_string($path)) {
                $result[] = (rtrim($path, '/') !== '' ? rtrim($path, '/') : '.');
            }
        }

        return $result;
    }
}
