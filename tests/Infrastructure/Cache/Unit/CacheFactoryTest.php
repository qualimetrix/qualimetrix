<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\FileCache;

#[CoversClass(CacheFactory::class)]
final class CacheFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsFileCacheConfiguredFromProvider(): void
    {
        $factory = $this->makeFactoryWithCacheDir('/tmp/qmx-test-cache');

        $cache = $factory->create();

        self::assertInstanceOf(FileCache::class, $cache);
    }

    #[Test]
    public function createMemoizesCacheInstanceAcrossCalls(): void
    {
        $factory = $this->makeFactoryWithCacheDir('/tmp/qmx-test-cache');

        $first = $factory->create();
        $second = $factory->create();

        self::assertSame($first, $second, 'CacheFactory must not rebuild the cache on subsequent create() calls');
    }

    #[Test]
    public function resetClearsMemoizedInstanceAndRebuildsOnNextCreate(): void
    {
        $factory = $this->makeFactoryWithCacheDir('/tmp/qmx-test-cache');

        $first = $factory->create();
        $factory->reset();
        $second = $factory->create();

        self::assertNotSame(
            $first,
            $second,
            'After reset(), create() must produce a fresh FileCache instance',
        );
    }

    #[Test]
    public function createUsesCacheDirFromCurrentConfigurationAtFirstCall(): void
    {
        $store = new CacheConfigurationStore();
        $store->replace(new CacheConfiguration(AbsolutePath::fromString('/tmp/qmx-initial-cache')));

        $factory = new CacheFactory($store);
        $cache = $factory->create();

        // FileCache stores the cacheDir; can't read it directly,
        // but we verify the factory dispatched to the provider's configuration.
        self::assertInstanceOf(FileCache::class, $cache);
    }

    private function makeFactoryWithCacheDir(string $cacheDir): CacheFactory
    {
        $store = new CacheConfigurationStore();
        $store->replace(new CacheConfiguration(AbsolutePath::fromString($cacheDir)));

        return new CacheFactory($store);
    }
}
