<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\Configurator;

use AiMessDetector\Analysis\Namespace_\ChainNamespaceDetector;
use AiMessDetector\Analysis\Namespace_\Psr4NamespaceDetector;
use AiMessDetector\Analysis\Namespace_\TokenizerNamespaceDetector;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Ast\FileParserInterface;
use AiMessDetector\Core\Namespace_\NamespaceDetectorInterface;
use AiMessDetector\Infrastructure\Ast\CachedFileParser;
use AiMessDetector\Infrastructure\Ast\FileParserFactory;
use AiMessDetector\Infrastructure\Ast\PhpFileParser;
use AiMessDetector\Infrastructure\Cache\CacheFactory;
use AiMessDetector\Infrastructure\Cache\CacheInterface;
use AiMessDetector\Infrastructure\Cache\CacheKeyGenerator;
use AiMessDetector\Infrastructure\Logging\DelegatingLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures cache, parser, and namespace detection infrastructure.
 */
final class ParserConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $this->registerCache($container);
        $this->registerParsers($container);
        $this->registerNamespaceDetection($container);
    }

    private function registerCache(ContainerBuilder $container): void
    {
        $container->register(CacheKeyGenerator::class);

        // CacheFactory creates FileCache lazily based on runtime configuration
        // Note: ConfigurationProviderInterface is synthetic, so we can't use autowiring here
        $container->register(CacheFactory::class)
            ->setArguments([new Reference(ConfigurationProviderInterface::class)])
            ->setPublic(true);

        // CacheInterface is created through factory
        $container->register(CacheInterface::class)
            ->setFactory([new Reference(CacheFactory::class), 'create'])
            ->setPublic(true);
    }

    private function registerParsers(ContainerBuilder $container): void
    {
        $container->register(PhpFileParser::class)
            ->setArguments([
                '$parser' => null,
                '$logger' => new Reference(DelegatingLogger::class),
            ]);

        $container->register(CachedFileParser::class)
            ->setArguments([
                new Reference(PhpFileParser::class),
                new Reference(CacheInterface::class),
                new Reference(CacheKeyGenerator::class),
            ]);

        $container->register(FileParserFactory::class)
            ->setArguments([
                new Reference(PhpFileParser::class),
                new Reference(CacheInterface::class),
                new Reference(CacheKeyGenerator::class),
                new Reference(ConfigurationProviderInterface::class),
            ]);

        // Register FileParserInterface using factory
        $container->register(FileParserInterface::class)
            ->setFactory([new Reference(FileParserFactory::class), 'create']);
    }

    private function registerNamespaceDetection(ContainerBuilder $container): void
    {
        $container->register(TokenizerNamespaceDetector::class);

        // Use default composer.json path; runtime config can override PSR-4 mappings
        $container->register(Psr4NamespaceDetector::class)
            ->setArguments(['composer.json']);

        // Chain detector with PSR-4 first, then tokenizer as fallback
        $container->register(ChainNamespaceDetector::class)
            ->setArguments([[
                new Reference(Psr4NamespaceDetector::class),
                new Reference(TokenizerNamespaceDetector::class),
            ]]);

        $container->setAlias(NamespaceDetectorInterface::class, ChainNamespaceDetector::class);
    }
}
