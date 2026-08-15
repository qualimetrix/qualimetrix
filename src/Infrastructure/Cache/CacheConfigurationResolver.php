<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;

final class CacheConfigurationResolver implements CacheConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document, AbsolutePath $projectRoot): CacheConfiguration
    {
        $directory = '.qmx-cache';
        foreach ($document->contributions(ConfigSchema::CACHE_DIR) as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                $directory = $candidate;
            }
        }

        $enabled = true;
        foreach ($document->contributions(ConfigSchema::CACHE_ENABLED) as $candidate) {
            if (\is_bool($candidate)) {
                $enabled = $candidate;
            }
        }

        return new CacheConfiguration(PathFactory::fromCliArgument($directory, $projectRoot), $enabled);
    }
}
