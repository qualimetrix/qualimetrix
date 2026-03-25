<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * Qualimetrix CLI application.
 */
final class Application extends BaseApplication
{
    public const string NAME = 'Qualimetrix';

    public function __construct()
    {
        parent::__construct(self::NAME, InstalledVersions::getRootPackage()['pretty_version']);
    }
}
