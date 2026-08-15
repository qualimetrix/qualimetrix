<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;

/**
 * Factory for creating cache instance based on runtime configuration.
 *
 * This enables lazy cache creation — the cache directory is read from the
 * instance-owned cache configuration store at the moment of first access.
 */
final class CacheFactory
{
    private ?CacheInterface $cache = null;

    public function __construct(
        private readonly CacheConfigurationStoreInterface $configurationStore,
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
            $this->cache = new FileCache($this->configurationStore->current()->directory);
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

    public function replaceConfiguration(CacheConfiguration $configuration): void
    {
        $this->configurationStore->replace($configuration);
    }

    public function resetConfiguration(): void
    {
        $this->reset();
        $this->configurationStore->reset();
    }
}
