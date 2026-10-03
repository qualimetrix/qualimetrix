<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Core\Path\AbsolutePath;

final class CacheDirectoryEligibility
{
    public static function unusableReason(AbsolutePath $directory): ?string
    {
        $nearest = self::nearestExistingComponent($directory->value());
        if ($nearest === null) {
            return 'No existing parent directory can be inspected.';
        }
        if (!is_dir($nearest)) {
            return \sprintf('The existing path component "%s" is not a directory.', $nearest);
        }

        return self::targetReason($nearest) ?? self::accessReason($nearest);
    }

    private static function nearestExistingComponent(string $path): ?string
    {
        while (!file_exists($path) && !is_link($path)) {
            $parent = \dirname($path);
            if ($parent === $path) {
                return null;
            }
            $path = $parent;
        }

        return $path;
    }

    private static function targetReason(string $path): ?string
    {
        try {
            TargetPath::resolve($path);
        } catch (FileTargetFailure $failure) {
            return $failure->kind === FileTargetFailureKind::Directory ? null : $failure->getMessage();
        }

        return null;
    }

    private static function accessReason(string $path): ?string
    {
        if (!is_writable($path)) {
            return \sprintf('The nearest existing directory "%s" is not writable.', $path);
        }
        if (!is_executable($path)) {
            return \sprintf('The nearest existing directory "%s" is not searchable.', $path);
        }

        return null;
    }
}
