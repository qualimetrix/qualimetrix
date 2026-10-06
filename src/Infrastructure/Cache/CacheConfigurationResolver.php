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
        $directory = CacheSection::DEFAULT_DIRECTORY;
        $configuredDirectory = $document->resolved()->get(CacheSection::KEY, 'dir');
        if ($configuredDirectory !== null) {
            $directory = CacheSection::acceptedDirectory($configuredDirectory);
        }

        $enabled = true;
        $configuredEnabled = $document->resolved()->get(CacheSection::KEY, 'enabled');
        if ($configuredEnabled !== null) {
            $enabled = $configuredEnabled->plain();
            if (!\is_bool($enabled)) {
                $configuredEnabled->refuse('Cache enabled must be a boolean.');
            }
        }

        $path = PathFactory::fromCliArgument($directory, $projectRoot);

        if (!$enabled) {
            return new CacheConfiguration($path, false);
        }

        $reason = CacheDirectoryEligibility::unusableReason($path);
        if ($reason === null) {
            return new CacheConfiguration($path);
        }

        $summary = \sprintf(
            'Cache directory "%s" is not writable or cannot be created: %s Point cache.dir (or --cache-dir)'
            . ' at a writable path, or disable the cache with --no-cache.',
            $path->value(),
            $reason,
        );
        if ($configuredDirectory !== null) {
            $configuredDirectory->refuse($summary);
        }

        return new CacheConfiguration($path, false, $summary);
    }

}
