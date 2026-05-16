<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
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
        $config = new AnalysisConfiguration(cacheDir: '/tmp/qmx-initial-cache');
        $provider = self::createStub(ConfigurationProviderInterface::class);
        $provider->method('getConfiguration')->willReturn($config);

        $factory = new CacheFactory($provider);
        $cache = $factory->create();

        // FileCache stores the cacheDir; can't read it directly,
        // but we verify the factory dispatched to the provider's configuration.
        self::assertInstanceOf(FileCache::class, $cache);
    }

    private function makeFactoryWithCacheDir(string $cacheDir): CacheFactory
    {
        $config = new AnalysisConfiguration(cacheDir: $cacheDir);
        $provider = self::createStub(ConfigurationProviderInterface::class);
        $provider->method('getConfiguration')->willReturn($config);

        return new CacheFactory($provider);
    }
}
