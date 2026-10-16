<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;

final class CacheConfigurationStoreTest extends TestCase
{
    #[Test]
    public function itDoesNotRetainAReplacedConfigurationAfterReset(): void
    {
        $store = new CacheConfigurationStore();
        $store->replace(new CacheConfiguration(AbsolutePath::fromString('/custom'), false));
        $store->reset();

        self::assertTrue($store->current()->enabled);
        self::assertNotSame('/custom', $store->current()->directory?->value());
    }
    #[Test]
    public function itCapturesEnvironmentAtTheRunReadBoundaryAndAfterReset(): void
    {
        $before = getenv('XDG_CACHE_HOME');
        $store = new CacheConfigurationStore();
        $base = sys_get_temp_dir() . '/qmx-cache-boundary-' . bin2hex(random_bytes(6));
        try {
            putenv('XDG_CACHE_HOME=' . $base . '/first');
            self::assertSame($base . '/first/qmx', $store->current()->directory?->value());
            putenv('XDG_CACHE_HOME=' . $base . '/second');
            self::assertSame($base . '/first/qmx', $store->current()->directory->value());
            $store->reset();
            self::assertSame($base . '/second/qmx', $store->current()->directory?->value());
            self::assertDirectoryDoesNotExist($base);
        } finally {
            putenv($before === false ? 'XDG_CACHE_HOME' : 'XDG_CACHE_HOME=' . $before);
        }
    }

}
