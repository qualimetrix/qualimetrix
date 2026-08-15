<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;

final class CacheConfigurationResolverTest extends TestCase
{
    #[Test]
    public function itAppliesOwnerDefaultsAndLastOverrides(): void
    {
        $configuration = (new CacheConfigurationResolver())->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['cache.dir' => 'var/cache', 'cache.enabled' => false]],
        ], AbsolutePath::fromString('/project')), AbsolutePath::fromString('/project'));

        self::assertSame('/project/var/cache', $configuration->directory->value());
        self::assertFalse($configuration->enabled);
    }
}
