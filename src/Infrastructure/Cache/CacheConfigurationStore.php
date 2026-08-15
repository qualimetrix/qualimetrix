<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;

final class CacheConfigurationStore implements CacheConfigurationStoreInterface
{
    private CacheConfiguration $configuration;
    public function __construct()
    {
        $this->configuration = self::defaults();
    }
    public function replace(CacheConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }
    public function current(): CacheConfiguration
    {
        return $this->configuration;
    }
    public function reset(): void
    {
        $this->configuration = self::defaults();
    }

    private static function defaults(): CacheConfiguration
    {
        $workingDirectory = getcwd();

        return new CacheConfiguration(AbsolutePath::fromString(
            ($workingDirectory !== false ? $workingDirectory : '.') . '/.qmx-cache',
        ));
    }
}
