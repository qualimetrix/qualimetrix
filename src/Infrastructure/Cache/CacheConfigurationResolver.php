<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
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

        $configuration = new CacheConfiguration(PathFactory::fromCliArgument($directory, $projectRoot), $enabled);

        // A cache that cannot be written is a cache that silently does nothing.
        // Only an enabled one makes a promise: `--no-cache` and
        // `cache.enabled: false` are entitled to an unusable path.
        if ($configuration->enabled) {
            $this->assertUsable($configuration);
        }

        return $configuration;
    }

    /**
     * A missing directory is not a miss — it is created here, the same way the
     * store would create it on first write. A path that cannot become a
     * writable directory is.
     */
    private function assertUsable(CacheConfiguration $configuration): void
    {
        $path = $configuration->directory->value();

        if ((is_dir($path) || @mkdir($path, 0755, true) || is_dir($path)) && is_writable($path)) {
            return;
        }

        // Resolved rather than CommandLine: the directory is merged from
        // `--cache-dir` and `cache.dir`, and which of them last named it is
        // no longer recoverable here.
        throw ConfigurationRefusal::aboutResolvedInput(
            \sprintf(
                'Cache directory "%s" is not writable. Point cache.dir (or --cache-dir) at a writable path, or disable the cache with --no-cache.',
                $path,
            ),
        );
    }
}
