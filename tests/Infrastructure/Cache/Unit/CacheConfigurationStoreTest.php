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
        self::assertNotSame('/custom', $store->current()->directory->value());
    }
}
