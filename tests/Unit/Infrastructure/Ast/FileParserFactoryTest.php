<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Ast;

use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationHolder;
use AiMessDetector\Infrastructure\Ast\CachedFileParser;
use AiMessDetector\Infrastructure\Ast\FileParserFactory;
use AiMessDetector\Infrastructure\Ast\PhpFileParser;
use AiMessDetector\Infrastructure\Cache\CacheInterface;
use AiMessDetector\Infrastructure\Cache\CacheKeyGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
            $this->createMock(CacheInterface::class),
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
            $this->createMock(CacheInterface::class),
            new CacheKeyGenerator(),
            $configProvider,
        );

        $parser = $factory->create();

        self::assertInstanceOf(PhpFileParser::class, $parser);
    }
}
