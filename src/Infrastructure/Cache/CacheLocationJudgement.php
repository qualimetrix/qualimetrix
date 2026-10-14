<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\ProcessOwner;
use Qualimetrix\Core\Path\AbsolutePath;

final class CacheLocationJudgement
{
    public static function reason(?AbsolutePath $directory, ?AbsolutePath $projectRoot): ?string
    {
        if ($directory === null) {
            return 'No user cache base supported by the POSIX path contract is available. Native Windows drive and UNC paths cannot be represented. Set an absolute supported XDG_CACHE_HOME or HOME, or use --cache-dir / cache.dir.';
        }
        $path = self::canonical($directory->value());
        if (!str_starts_with($path, '/')) {
            return \sprintf('Canonical cache directory "%s" cannot be represented by the POSIX path contract. Use --cache-dir / cache.dir with a supported path, or disable caching.', $path);
        }
        $project = $projectRoot === null ? '/' : self::canonical($projectRoot->value());
        if ($project !== '/' && ($path === $project || str_starts_with($path, rtrim($project, '/') . '/'))) {
            return \sprintf('Cache directory "%s" is inside the project root "%s". Use --cache-dir / cache.dir outside the checkout, or set --working-dir to the project directory.', $path, $project);
        }

        return CacheDirectoryEligibility::unusableMessage($directory);
    }

    public static function trustReason(AbsolutePath $directory): ?string
    {
        $path = $directory->value();
        if (\DIRECTORY_SEPARATOR !== '/' || !is_dir($path)) {
            return null;
        }
        try {
            $euid = ProcessOwner::effectiveUid($path);
        } catch (FileTargetFailure $failure) {
            return $failure->getMessage();
        }
        $owner = fileowner($path);
        $mode = fileperms($path);
        if ($owner === $euid && $mode !== false && ($mode & 0022) === 0) {
            return null;
        }

        return \sprintf('Cache directory "%s" is untrusted: owner %s (effective uid %d), mode %s. Use --cache-dir / cache.dir pointing to a directory owned by this user without group or other write permission.', $path, $owner === false ? 'unknown' : (string) $owner, $euid, $mode === false ? 'unknown' : \sprintf('%04o', $mode & 0777));
    }

    private static function canonical(string $path): string
    {
        $tail = [];
        while (!file_exists($path) && !is_link($path)) {
            $parent = \dirname($path);
            if ($parent === $path) {
                break;
            }
            array_unshift($tail, basename($path));
            $path = $parent;
        }

        $real = realpath($path);
        $canonical = rtrim($real === false ? $path : $real, '/') . ($tail === [] ? '' : '/' . implode('/', $tail));

        return $canonical === '' ? '/' : $canonical;
    }
}
