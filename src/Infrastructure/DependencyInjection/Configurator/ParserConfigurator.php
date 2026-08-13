<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
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
    }

    private function registerCache(ContainerBuilder $container): void
    {
        $container->register(CacheKeyGenerator::class);

        // CacheFactory creates FileCache lazily based on runtime configuration
        // Note: TransitionalRuntimeConfigurationProviderInterface is synthetic, so we can't use autowiring here
        $container->register(CacheFactory::class)
            ->setArguments([new Reference(TransitionalRuntimeConfigurationProviderInterface::class)])
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
                new Reference(TransitionalRuntimeConfigurationProviderInterface::class),
            ]);

        // Register FileParserInterface using factory
        $container->register(FileParserInterface::class)
            ->setFactory([new Reference(FileParserFactory::class), 'create']);
    }

}
