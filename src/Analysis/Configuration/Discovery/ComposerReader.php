<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Discovery;

use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;

final class ComposerReader implements ComposerAutoloadPathReaderInterface
{
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

    /** @return ?list<string> */
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

        // Every section merged into one list: both readers of it — the
        // default paths and the scope denominator — ask about containment,
        // not about which mechanism served a path.
        $targets = array_values(array_unique([
            ...$this->prefixMapPaths($autoload['psr-4'] ?? null),
            ...$this->prefixMapPaths($autoload['psr-0'] ?? null),
            ...$this->expandWildcards($this->pathListPaths($autoload['classmap'] ?? null), \dirname($composerJsonPath)),
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
     * Composer accepts `*` in a `classmap` entry and expands it to the
     * directories it matches. Left unexpanded, the entry names no path on
     * disk: a run with no `paths` would be refused over a manifest Composer
     * itself accepts. An entry matching nothing is kept as written, so it
     * meets the same refusal as any other missing target — Composer refuses
     * it too.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function expandWildcards(array $paths, string $baseDirectory): array
    {
        $expanded = [];
        foreach ($paths as $path) {
            $matches = str_contains($path, '*')
                ? glob(str_starts_with($path, '/') ? $path : $baseDirectory . '/' . $path, \GLOB_ONLYDIR)
                : false;

            if ($matches === false || $matches === []) {
                $expanded[] = $path;

                continue;
            }

            sort($matches);
            foreach ($matches as $match) {
                $expanded[] = str_starts_with($match, $baseDirectory . '/')
                    ? substr($match, \strlen($baseDirectory) + 1)
                    : $match;
            }
        }

        return $expanded;
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
