<?php

declare(strict_types=1);

namespace Qualimetrix\Core;

use Composer\InstalledVersions;

/**
 * Provides the Qualimetrix package version at runtime.
 */
final class Version
{
    /**
     * Always resolved by package name, never through the root package.
     *
     * When Qualimetrix is installed as a dependency the root package is the
     * consuming project, so reading the root version reports that project's
     * version instead of ours. Inside this repository the two coincide, which
     * is why the mistake stayed invisible locally.
     */
    private const PACKAGE = 'qualimetrix/qualimetrix';

    private const FALLBACK = 'dev';

    public static function get(): string
    {
        // Running outside a Composer install of this package (e.g. from a
        // stand-alone archive) leaves nothing to resolve.
        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            return self::FALLBACK;
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? self::FALLBACK;
    }
}
