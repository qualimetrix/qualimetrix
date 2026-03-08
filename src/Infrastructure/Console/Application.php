<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * AI Mess Detector CLI application.
 */
final class Application extends BaseApplication
{
    public const string NAME = 'AI Mess Detector';

    public function __construct()
    {
        parent::__construct(self::NAME, InstalledVersions::getRootPackage()['pretty_version']);
    }
}
