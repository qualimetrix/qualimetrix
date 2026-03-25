<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Namespace_\NamespaceDetectorFactory;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Namespace_\NamespaceDetectorInterface;
use Qualimetrix\Infrastructure\Ast\CachedFileParser;
use Qualimetrix\Infrastructure\Ast\FileParserFactory;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheInterface;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
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
        // Factory reads namespace.composer_json from runtime config
        // Note: namespace.strategy config is accepted but only 'chain' is implemented.
        // To support strategy selection, extend NamespaceDetectorFactory.
        $container->register(NamespaceDetectorFactory::class)
            ->setArguments([new Reference(ConfigurationProviderInterface::class)]);

        $container->register(NamespaceDetectorInterface::class)
            ->setFactory([new Reference(NamespaceDetectorFactory::class), 'create']);
    }
}
