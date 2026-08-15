<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Infrastructure\Ast\CachedFileParser;
use Qualimetrix\Infrastructure\Ast\FileParserFactory;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheInterface;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;
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
    }

    private function registerCache(ContainerBuilder $container): void
    {
        $container->register(CacheKeyGenerator::class);
        $container->register(CacheConfigurationStore::class);
        $container->setAlias(CacheConfigurationStoreInterface::class, CacheConfigurationStore::class);
        $container->register(CacheConfigurationResolver::class);
        $container->setAlias(CacheConfigurationResolverInterface::class, CacheConfigurationResolver::class);

        $container->register(CacheFactory::class)
            ->setArguments([new Reference(CacheConfigurationStoreInterface::class)])
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
                new Reference(CacheFactory::class),
                new Reference(CacheKeyGenerator::class),
            ]);

        $container->register(FileParserFactory::class)
            ->setArguments([
                new Reference(PhpFileParser::class),
                new Reference(CacheFactory::class),
                new Reference(CacheKeyGenerator::class),
                new Reference(CacheConfigurationStoreInterface::class),
            ]);

        // Register FileParserInterface using factory
        $container->register(FileParserInterface::class)
            ->setFactory([new Reference(FileParserFactory::class), 'create']);
    }

}
