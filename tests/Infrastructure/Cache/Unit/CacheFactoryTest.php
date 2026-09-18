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
    public function itReturnsAFileCacheConfiguredFromTheProvider(): void
    {
        $factory = $this->makeFactoryWithCacheDir('/tmp/qmx-test-cache');

        $cache = $factory->create();

        self::assertInstanceOf(FileCache::class, $cache);
    }

    #[Test]
    public function itMemoizesTheCacheInstanceAcrossCalls(): void
    {
        $factory = $this->makeFactoryWithCacheDir('/tmp/qmx-test-cache');

        $first = $factory->create();
        $second = $factory->create();

        self::assertSame($first, $second, 'CacheFactory must not rebuild the cache on subsequent create() calls');
    }

    #[Test]
    public function itRebuildsTheCacheOnTheNextCreateAfterReset(): void
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
    public function itUsesTheCacheDirFromTheConfigurationAtFirstCall(): void
    {
        $cache = $this->makeFactoryWithCacheDir('/tmp/qmx-initial-cache')->create();

        self::assertInstanceOf(FileCache::class, $cache);
        self::assertSame('/tmp/qmx-initial-cache', $cache->getDirectory()->value());
    }

    #[Test]
    public function itTakesTheCacheDirAsItWasAtTheFirstCall(): void
    {
        $store = new CacheConfigurationStore();
        $store->replace(new CacheConfiguration(AbsolutePath::fromString('/tmp/qmx-initial-cache')));

        $factory = new CacheFactory($store);
        $first = $factory->create();

        $store->replace(new CacheConfiguration(AbsolutePath::fromString('/tmp/qmx-second-cache')));

        self::assertInstanceOf(FileCache::class, $first);
        self::assertSame('/tmp/qmx-initial-cache', $first->getDirectory()->value());
        self::assertSame($first, $factory->create());
    }

    private function makeFactoryWithCacheDir(string $cacheDir): CacheFactory
    {
        $store = new CacheConfigurationStore();
        $store->replace(new CacheConfiguration(AbsolutePath::fromString($cacheDir)));

        return new CacheFactory($store);
    }
}
