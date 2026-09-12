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
    private const string DEFAULT_DIRECTORY = '.qmx-cache';

    public function resolve(ConfigurationDocument $document, AbsolutePath $projectRoot): CacheConfiguration
    {
        $directory = self::DEFAULT_DIRECTORY;
        foreach ($document->contributions(ConfigSchema::CACHE_DIR) as $candidate) {
            $directory = self::acceptedDirectory($candidate);
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
        //
        // Measured, because the two refusals below differ on it: `--no-cache`
        // DOES carry a run past this check (exit 2 where the same directory
        // without the flag exits 3), and does NOT carry one past the
        // empty-value refusal above, which runs before `enabled` is consulted
        // at all. Only the refusal the flag can actually answer may name it.
        if ($configuration->enabled) {
            $this->assertUsable($configuration);
        }

        return $configuration;
    }

    /**
     * A directory name is the one thing this value can be. Anything else was
     * silently dropped here before, and the run then wrote into `.qmx-cache`
     * while reporting as though it had honoured the configured path.
     */
    private static function acceptedDirectory(mixed $candidate): string
    {
        if (!\is_string($candidate)) {
            throw ConfigurationRefusal::aboutResolvedInput(
                \sprintf(
                    'Invalid value for "%s": expected a directory path, got %s.',
                    ConfigSchema::CACHE_DIR,
                    get_debug_type($candidate),
                ),
                ConfigSchema::CACHE_DIR,
            );
        }

        if ($candidate === '') {
            throw ConfigurationRefusal::aboutResolvedInput(
                \sprintf(
                    'Invalid value for "%s": a directory path cannot be empty. Omit the key to use the default (%s).',
                    ConfigSchema::CACHE_DIR,
                    self::DEFAULT_DIRECTORY,
                ),
                ConfigSchema::CACHE_DIR,
            );
        }

        return $candidate;
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
                'Cache directory "%s" is not writable. Point cache.dir (or --cache-dir) at a writable path,'
                . ' or disable the cache with --no-cache.',
                $path,
            ),
        );
    }
}
