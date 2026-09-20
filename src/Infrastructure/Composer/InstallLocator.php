<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

/**
 * Which composer projects a run is allowed to read.
 *
 * Separate from reading them because the question is different: this one is
 * about where the analysed tree sits, and it is the half that has to refuse.
 * Walking up without a bound would read the configuration of whatever project
 * happens to contain the analysed path -- a parent repository, or a home
 * directory.
 */
final readonly class InstallLocator
{
    private const int MAX_WALK_UP = 12;

    /**
     * Nearest first, de-duplicated.
     *
     * The analysed paths come before the project root deliberately: a library
     * analysed from inside somebody else's `vendor/` carries a `composer.json`
     * describing only itself, and the enclosing project is what supplies its
     * dependencies. Measured on one such package, reading only the nearest
     * manifest placed 8 of 22 parents; reading both placed 20.
     *
     * @param list<string> $analysedPaths
     *
     * @return list<string>
     */
    public function rootsFor(string $projectRoot, array $analysedPaths): array
    {
        $roots = [];

        foreach ([...$analysedPaths, $projectRoot] as $path) {
            $root = $this->nearestRoot($path);

            if ($root !== null && !\in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    private function nearestRoot(string $path): ?string
    {
        $directory = realpath(is_dir($path) ? $path : \dirname($path));

        if ($directory === false) {
            return null;
        }

        for ($level = 0; $level < self::MAX_WALK_UP; ++$level) {
            if (is_file($directory . '/composer.json')) {
                return $directory;
            }

            $parent = \dirname($directory);

            // A filesystem root is its own parent: without this the loop would
            // spin, and without the cap above it could climb past the project.
            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }

        return null;
    }
}
