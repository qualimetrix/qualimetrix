<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\FormatterCompilerPass;
use AiMessDetector\Reporting\Formatter\FormatterRegistry;
use AiMessDetector\Reporting\Formatter\Json\JsonFormatter;
use AiMessDetector\Reporting\Formatter\TextFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(FormatterCompilerPass::class)]
final class FormatterCompilerPassTest extends TestCase
{
    #[Test]
    public function collectsTaggedFormattersIntoRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->register(FormatterRegistry::class);
        $container->register(TextFormatter::class)
            ->addTag(FormatterCompilerPass::TAG);
        $container->register(JsonFormatter::class)
            ->addTag(FormatterCompilerPass::TAG);

        $pass = new FormatterCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(FormatterRegistry::class);
        $formatters = $definition->getArgument(0);

        self::assertCount(2, $formatters);
        self::assertInstanceOf(Reference::class, $formatters[0]);
        self::assertInstanceOf(Reference::class, $formatters[1]);
    }

    #[Test]
    public function doesNothingWhenRegistryNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(TextFormatter::class)
            ->addTag(FormatterCompilerPass::TAG);

        $pass = new FormatterCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(FormatterRegistry::class));
    }

    #[Test]
    public function setsEmptyArrayWhenNoTaggedServices(): void
    {
        $container = new ContainerBuilder();
        $container->register(FormatterRegistry::class);

        $pass = new FormatterCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(FormatterRegistry::class);

        self::assertSame([], $definition->getArgument(0));
    }
}
