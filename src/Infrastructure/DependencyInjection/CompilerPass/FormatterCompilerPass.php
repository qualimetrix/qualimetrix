<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Reporting\Formatter\FormatterRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged with 'aimd.formatter' and injects them into FormatterRegistry.
 */
final class FormatterCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'aimd.formatter';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FormatterRegistry::class)) {
            return;
        }

        $definition = $container->getDefinition(FormatterRegistry::class);
        $formatters = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $formatters[] = new Reference($id);
        }

        $definition->setArgument(0, $formatters);
    }
}
