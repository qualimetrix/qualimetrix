<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DeclaredDependencies;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The PHP a consumer installs and executes.
 *
 * One definition, because this group asks more than one question of the same
 * population: which packages that code reaches, and which PHP extensions it
 * needs. Two copies of "what ships" would drift, and the half that drifted
 * would keep passing.
 *
 * `composer.json`'s `bin` is read rather than assumed: the console entry point
 * is extensionless, so a directory walk over `src/` alone would miss the one
 * file every consumer certainly runs.
 *
 * The lock's production section is here for the same reason. Both controls
 * ask what a `--no-dev` install resolves, and a second copy of "production
 * packages" would drift from this one without either copy failing.
 */
final class ShippedTree
{
    /**
     * @return list<string>
     */
    public static function files(string $root): array
    {
        $files = [];

        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/src',
            FilesystemIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $entry */
        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        foreach (self::entryPoints($root) as $entryPoint) {
            $files[] = $root . '/' . $entryPoint;
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    public static function entryPoints(string $root): array
    {
        $manifest = self::manifest($root);
        $bin = $manifest['bin'] ?? null;

        if (!\is_array($bin) || $bin === []) {
            throw new RuntimeException('composer.json declares no bin entries, so the shipped tree would be src/ alone.');
        }

        $entryPoints = [];

        foreach ($bin as $entryPoint) {
            if (!\is_string($entryPoint)) {
                throw new RuntimeException('composer.json declares a non-string bin entry.');
            }

            $entryPoints[] = $entryPoint;
        }

        return $entryPoints;
    }

    /**
     * The `packages` section of `composer.lock`.
     *
     * Production only: a dev-only package standing in for something says
     * nothing about what a consumer's `--no-dev` install resolves.
     *
     * @return list<array<string, mixed>>
     */
    public static function productionLockPackages(string $root): array
    {
        $contents = file_get_contents($root . '/composer.lock');

        if ($contents === false) {
            throw new RuntimeException('composer.lock is unreadable at ' . $root . '.');
        }

        $lock = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($lock)) {
            throw new RuntimeException('composer.lock does not contain a JSON object.');
        }

        return self::productionPackagesOf($lock);
    }

    /**
     * The production half of an already-decoded lock document.
     *
     * Split from the read above so the section boundary can be planted. Which
     * half is taken is the whole promise of this method, and a decoded
     * document is the only input that can carry a dev-only package to prove
     * the boundary holds — this lock has none that would show the
     * difference.
     *
     * @param array<string, mixed> $lock
     *
     * @return list<array<string, mixed>>
     */
    public static function productionPackagesOf(array $lock): array
    {
        if (!\is_array($lock['packages'] ?? null) || $lock['packages'] === []) {
            throw new RuntimeException('composer.lock lists no production package.');
        }

        $packages = [];

        foreach ($lock['packages'] as $package) {
            if (!\is_array($package)) {
                throw new RuntimeException('composer.lock carries a non-object package entry.');
            }

            $packages[] = $package;
        }

        return $packages;
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(string $root): array
    {
        $contents = file_get_contents($root . '/composer.json');

        if ($contents === false) {
            throw new RuntimeException('composer.json is unreadable at ' . $root . '.');
        }

        $manifest = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($manifest)) {
            throw new RuntimeException('composer.json does not contain a JSON object.');
        }

        return $manifest;
    }
}
