<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationHolder;
use Qualimetrix\Infrastructure\Ast\CachedFileParser;
use Qualimetrix\Infrastructure\Ast\FileParserFactory;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;

#[CoversClass(FileParserFactory::class)]
final class FileParserFactoryTest extends TestCase
{
    #[Test]
    public function itCreatesCachedParserWhenCacheEnabled(): void
    {
        $config = new AnalysisConfiguration(
            cacheDir: '/tmp/cache',
            cacheEnabled: true,
        );

        $configProvider = new ConfigurationHolder();
        $configProvider->setConfiguration($config);

        $factory = new FileParserFactory(
            new PhpFileParser(),
            new CacheFactory($configProvider),
            new CacheKeyGenerator(),
            $configProvider,
        );

        $parser = $factory->create();

        self::assertInstanceOf(CachedFileParser::class, $parser);
    }

    #[Test]
    public function itCreatesDirectParserWhenCacheDisabled(): void
    {
        $config = new AnalysisConfiguration(
            cacheDir: '/tmp/cache',
            cacheEnabled: false,
        );

        $configProvider = new ConfigurationHolder();
        $configProvider->setConfiguration($config);

        $factory = new FileParserFactory(
            new PhpFileParser(),
            new CacheFactory($configProvider),
            new CacheKeyGenerator(),
            $configProvider,
        );

        $parser = $factory->create();

        self::assertInstanceOf(PhpFileParser::class, $parser);
    }
}
