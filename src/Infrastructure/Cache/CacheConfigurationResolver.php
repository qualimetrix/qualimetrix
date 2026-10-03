<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;

final class CacheConfigurationResolver implements CacheConfigurationResolverInterface
{
    private const string DEFAULT_DIRECTORY = '.qmx-cache';

    public function resolve(ConfigurationDocument $document, AbsolutePath $projectRoot): CacheConfiguration
    {
        $directory = self::DEFAULT_DIRECTORY;
        $configuredDirectory = $document->resolved()->get(ConfigurationRoot::Cache->value, 'dir');
        if ($configuredDirectory !== null) {
            $directory = self::acceptedDirectory($configuredDirectory);
        }

        $enabled = true;
        $configuredEnabled = $document->resolved()->get(ConfigurationRoot::Cache->value, 'enabled');
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

    /**
     * A directory name is the one thing this value can be. Anything else was
     * silently dropped here before, and the run then wrote into `.qmx-cache`
     * while reporting as though it had honoured the configured path.
     */
    private static function acceptedDirectory(ResolvedValueInterface $configuredDirectory): string
    {
        $candidate = $configuredDirectory->plain();

        if (!\is_string($candidate)) {
            $configuredDirectory->refuse(
                \sprintf(
                    'Invalid value for "%s": expected a directory path, got %s.',
                    ConfigSchema::CACHE_DIR,
                    get_debug_type($candidate),
                ),
            );
        }

        if ($candidate === '') {
            $configuredDirectory->refuse(
                \sprintf(
                    'Invalid value for "%s": a directory path cannot be empty. Omit the key to use the default (%s).',
                    ConfigSchema::CACHE_DIR,
                    self::DEFAULT_DIRECTORY,
                ),
            );
        }

        return $candidate;
    }

}
