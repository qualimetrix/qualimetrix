<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\AstEntryCodec;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\FileCache;
use Qualimetrix\Infrastructure\Cache\Root\CacheSource;
use ReflectionProperty;
use stdClass;
use Stringable;

#[CoversClass(CacheFactory::class)]
final class CacheFactoryTest extends TestCase
{
    #[Test]
    public function itReturnsAFileCacheConfiguredFromTheProvider(): void
    {
        $factory = $this->makeFactoryWithCacheDir('/tmp/qmx-test-cache');

        $cache = $factory->create();

        self::assertSame('/tmp/qmx-test-cache', $cache->getDirectory()->value());
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

        self::assertSame('/tmp/qmx-initial-cache', $first->getDirectory()->value());
        self::assertSame($first, $factory->create());
    }

    #[Test]
    public function itOpensOncePerRunAndReportsOnlyTheCoordinatorDiscardCount(): void
    {
        $directory = sys_get_temp_dir() . '/qmx-cache-open-' . bin2hex(random_bytes(6));
        $store = new CacheConfigurationStore();
        $store->replace(new CacheConfiguration(AbsolutePath::fromString($directory)));
        $factory = new CacheFactory($store);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        try {
            $factory->configureLogger($logger);
            self::assertSame($directory, $factory->open()?->value());
            $cursor = file_get_contents($directory . '/ast-v' . AstEntryCodec::FORMAT_VERSION . '/.sweep');
            self::assertNotFalse($cursor);
            $factory->open();
            self::assertSame($cursor, file_get_contents($directory . '/ast-v' . AstEntryCodec::FORMAT_VERSION . '/.sweep'));
            foreach (['a', 'b'] as $prefix) {
                $key = str_repeat($prefix, 64);
                $factory->create()->set($key, []);
                file_put_contents($directory . '/ast-v' . AstEntryCodec::FORMAT_VERSION . '/' . $prefix . $prefix . '/' . $key, serialize([new stdClass()]));
                $factory->create()->get($key);
            }
            $factory->reportDiscardedEntries();
            $factory->reportDiscardedEntries();
            self::assertCount(1, $logger->warnings);
            self::assertStringStartsWith('2 cache entries discarded', $logger->warnings[0]);
            $factory->reset();
            self::assertInstanceOf(NullLogger::class, (new ReflectionProperty($factory, 'logger'))->getValue($factory));
            $factory->configureLogger($logger);
            $factory->open();
            $factory->reportDiscardedEntries();
            self::assertCount(1, $logger->warnings, 'A zero-discard run must not warn.');
            self::assertSame(0, $factory->create()->discardedEntryCount());
            $key = str_repeat('c', 64);
            $factory->create()->set($key, []);
            file_put_contents($directory . '/ast-v' . AstEntryCodec::FORMAT_VERSION . '/cc/' . $key, 'invalid');
            $factory->create()->get($key);
            $factory->reportDiscardedEntries();
            self::assertCount(2, $logger->warnings);
            self::assertStringStartsWith('1 cache entry discarded', $logger->warnings[1]);
        } finally {
            $factory->create()->clear();
            @rmdir($directory);
        }
    }

    #[Test]
    public function itDisablesAnUnusableDefaultOnceAndDoesNotCreateIt(): void
    {
        $project = AbsolutePath::fromString(__DIR__);
        $store = new CacheConfigurationStore();
        $store->replace(new CacheConfiguration(AbsolutePath::fromString(__DIR__ . '/cache'), source: CacheSource::default(), projectRoot: $project));
        $factory = new CacheFactory($store);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('inside the project root'));
        $factory->configureLogger($logger);
        self::assertNull($factory->open());
        self::assertNull($factory->open());
        self::assertDirectoryDoesNotExist(__DIR__ . '/cache');
    }

    #[Test]
    public function itClearsDisabledCachingWithoutResolvingTheParserVersionOrSweeping(): void
    {
        $directory = sys_get_temp_dir() . '/qmx-clear-disabled-' . bin2hex(random_bytes(6));
        $store = new CacheConfigurationStore();
        $store->replace(new CacheConfiguration(AbsolutePath::fromString($directory), false));
        $generator = new \Qualimetrix\Infrastructure\Cache\CacheKeyGenerator();
        $factory = new CacheFactory($store, $generator);
        mkdir($directory . '/ast-v99/aa', 0700, true);
        file_put_contents($directory . '/ast-v99/aa/' . str_repeat('a', 64), 'stored entry');
        try {
            $outcome = $factory->clear();
            self::assertNotNull($outcome);
            self::assertTrue($outcome->complete);
            self::assertSame(0, $outcome->remaining);
            self::assertNull((new ReflectionProperty($generator, 'parserVersion'))->getValue($generator));
            self::assertDirectoryDoesNotExist($directory . '/ast-v99');
            self::assertDirectoryDoesNotExist($directory . '/ast-v1');
        } finally {
            (new FileCache(AbsolutePath::fromString($directory)))->clear();
            @rmdir($directory);
        }
    }

    private function makeFactoryWithCacheDir(string $cacheDir): CacheFactory
    {
        $store = new CacheConfigurationStore();
        $store->replace(new CacheConfiguration(AbsolutePath::fromString($cacheDir)));

        return new CacheFactory($store);
    }
}
