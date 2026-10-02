<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use InvalidArgumentException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;

final class PathsNormalizer
{
    /**
     * @param list<string> $paths
     *
     * @return list<AbsolutePath>
     */
    public static function normalize(AbsolutePath $root, array $paths): array
    {
        $canonicalRoot = realpath($root->value());
        $canonicalRootPath = $canonicalRoot === false ? $root : AbsolutePath::fromString($canonicalRoot);
        $normalized = [];

        foreach ($paths as $path) {
            $absolute = PathFactory::fromCliArgument($path, $root);
            self::assertAdmittedPath($path, $absolute, $root, $canonicalRootPath);
            $normalized[] = $absolute;
        }

        return $normalized;
    }

    private static function assertAdmittedPath(string $path, AbsolutePath $absolute, AbsolutePath $root, AbsolutePath $canonicalRootPath): void
    {
        if ($absolute->equals($root) || $absolute->equals($canonicalRootPath)) {
            return;
        }
        $directory = $absolute->isDirectory();
        $resolved = realpath($directory ? $absolute->value() : \dirname($absolute->value()));
        $insideWrittenRoot = $absolute->tryRelativizeTo($root) !== null;
        $insideCanonicalRoot = $absolute->tryRelativizeTo($canonicalRootPath) !== null;
        if (!$insideWrittenRoot && !$insideCanonicalRoot && $resolved === false) {
            self::refuseOutside($path, $root);
        }
        if ($resolved !== false) {
            self::assertResolvedPath($path, AbsolutePath::fromString($resolved), $root, $canonicalRootPath);
        }

    }

    private static function assertResolvedPath(string $path, AbsolutePath $resolvedPath, AbsolutePath $root, AbsolutePath $canonicalRootPath): void
    {
        if (!$resolvedPath->equals($canonicalRootPath) && $resolvedPath->tryRelativizeTo($canonicalRootPath) === null) {
            self::refuseOutside($path, $root);
        }
    }

    private static function refuseOutside(string $path, AbsolutePath $root): never
    {
        throw new InvalidArgumentException(\sprintf(
            'Analysis path "%s" is outside project root "%s". Choose a path inside the project or change --working-dir.',
            $path,
            $root->value(),
        ));
    }
}
