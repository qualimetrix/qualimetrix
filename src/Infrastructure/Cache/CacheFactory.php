<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Cache;

use AiMessDetector\Configuration\ConfigurationProviderInterface;

/**
 * Factory for creating cache instance based on runtime configuration.
 *
 * This enables lazy cache creation — the cache directory is determined
 * from ConfigurationHolder at the moment of first cache access.
 */
final class CacheFactory
{
    private ?CacheInterface $cache = null;

    public function __construct(
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {}

    /**
     * Creates or returns cached FileCache instance.
     *
     * Uses cacheDir from the current configuration. If configuration
     * changes after cache creation, the old cache directory is still used.
     */
    public function create(): CacheInterface
    {
        if ($this->cache === null) {
            $config = $this->configurationProvider->getConfiguration();
            $this->cache = new FileCache($config->cacheDir);
        }

        return $this->cache;
    }

    /**
     * Clears the cached instance (useful for testing).
     */
    public function reset(): void
    {
        $this->cache = null;
    }
}
