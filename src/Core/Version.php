<?php

declare(strict_types=1);

namespace Qualimetrix\Core;

use Composer\InstalledVersions;

/**
 * Provides the Qualimetrix package version at runtime.
 */
final class Version
{
    public static function get(): string
    {
        return InstalledVersions::getRootPackage()['pretty_version'] ?? 'dev';
    }
}
