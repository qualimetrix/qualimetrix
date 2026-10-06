<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;

final class CacheConfigurationResolver implements CacheConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document, AbsolutePath $projectRoot): CacheConfiguration
    {
        $configuredDirectory = $document->resolved()->get(CacheSection::KEY, 'dir');
        $directory = CacheSection::directory($configuredDirectory);
        $enabled = CacheSection::enabled($document->resolved()->get(CacheSection::KEY, 'enabled'));
        $path = PathFactory::fromCliArgument($directory, $projectRoot);

        if (!$enabled) {
            return new CacheConfiguration($path, false);
        }

        $summary = CacheDirectoryEligibility::unusableMessage($path);
        if ($summary === null) {
            return new CacheConfiguration($path);
        }

        if ($configuredDirectory !== null) {
            $configuredDirectory->refuse($summary);
        }

        return new CacheConfiguration($path, false, $summary);
    }

}
