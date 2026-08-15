<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Ast\CachedFileParser;
use Qualimetrix\Infrastructure\Ast\FileParserFactory;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;

#[CoversClass(FileParserFactory::class)]
final class FileParserFactoryTest extends TestCase
{
    #[Test]
    public function itCreatesCachedParserWhenCacheEnabled(): void
    {
        $configurationStore = new CacheConfigurationStore();
        $configurationStore->replace(new CacheConfiguration(AbsolutePath::fromString('/tmp/cache'), true));

        $factory = new FileParserFactory(
            new PhpFileParser(),
            new CacheFactory($configurationStore),
            new CacheKeyGenerator(),
            $configurationStore,
        );

        $parser = $factory->create();

        self::assertInstanceOf(CachedFileParser::class, $parser);
    }

    #[Test]
    public function itCreatesDirectParserWhenCacheDisabled(): void
    {
        $configurationStore = new CacheConfigurationStore();
        $configurationStore->replace(new CacheConfiguration(AbsolutePath::fromString('/tmp/cache'), false));

        $factory = new FileParserFactory(
            new PhpFileParser(),
            new CacheFactory($configurationStore),
            new CacheKeyGenerator(),
            $configurationStore,
        );

        $parser = $factory->create();

        self::assertInstanceOf(PhpFileParser::class, $parser);
    }
}
