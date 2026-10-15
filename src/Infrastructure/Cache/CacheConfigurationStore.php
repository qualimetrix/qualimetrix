<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;

use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Cache\Root\CacheSource;
use Qualimetrix\Infrastructure\Cache\Root\DefaultCacheDirectory;

final class CacheConfigurationStore implements CacheConfigurationStoreInterface
{
    private ?CacheConfiguration $configuration = null;
    public function __construct() {}
    public function replace(CacheConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }
    public function current(): CacheConfiguration
    {
        return $this->configuration ??= self::defaults();
    }
    public function reset(): void
    {
        $this->configuration = null;
    }

    private static function defaults(): CacheConfiguration
    {
        $workingDirectory = getcwd();

        return new CacheConfiguration(
            DefaultCacheDirectory::for(getenv(), \PHP_OS_FAMILY),
            source: CacheSource::default(),
            projectRoot: $workingDirectory === false || !str_starts_with($workingDirectory, '/') ? null : AbsolutePath::fromString($workingDirectory),
        );
    }
}
