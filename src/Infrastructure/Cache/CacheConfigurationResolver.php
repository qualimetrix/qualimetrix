<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
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

        $configuration = new CacheConfiguration(PathFactory::fromCliArgument($directory, $projectRoot), $enabled);

        // A cache that cannot be written is a cache that silently does nothing.
        // Only an enabled one makes a promise: `--no-cache` and
        // `cache.enabled: false` are entitled to an unusable path.
        //
        // Measured, because the two refusals below differ on it: `--no-cache`
        // DOES carry a run past this check (exit 2 where the same directory
        // without the flag exits 3), and does NOT carry one past the
        // empty-value refusal above, which runs before `enabled` is consulted
        // at all. Only the refusal the flag can actually answer may name it.
        if ($configuration->enabled) {
            $this->assertUsable($configuration, $configuredDirectory);
        }

        return $configuration;
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

    /**
     * A missing directory is not a miss — it is created here, the same way the
     * store would create it on first write. A path that cannot become a
     * writable directory is.
     */
    private function assertUsable(CacheConfiguration $configuration, ?ResolvedValueInterface $configuredDirectory): void
    {
        $path = $configuration->directory->value();

        if ((is_dir($path) || @mkdir($path, 0755, true) || is_dir($path)) && is_writable($path)) {
            return;
        }

        $summary = \sprintf(
            'Cache directory "%s" is not writable. Point cache.dir (or --cache-dir) at a writable path,'
            . ' or disable the cache with --no-cache.',
            $path,
        );

        if ($configuredDirectory !== null) {
            $configuredDirectory->refuse($summary);
        }

        throw ConfigurationRefusal::aboutInput(ConfigurationOrigin::of(ConfigurationSource::Defaults), $summary);
    }
}
