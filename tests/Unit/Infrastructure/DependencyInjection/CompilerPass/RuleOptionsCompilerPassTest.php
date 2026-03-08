<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use AiMessDetector\Rules\Complexity\ComplexityOptions;
use AiMessDetector\Rules\Complexity\ComplexityRule;
use AiMessDetector\Rules\Size\ClassCountRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(RuleOptionsCompilerPass::class)]
final class RuleOptionsCompilerPassTest extends TestCase
{
    #[Test]
    public function registersOptionsServiceViaFactory(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsFactory::class)->setSynthetic(true);
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        // Options service should be registered
        self::assertTrue($container->hasDefinition(ComplexityOptions::class));

        // Options should use RuleOptionsFactory::create as factory
        $optionsDef = $container->getDefinition(ComplexityOptions::class);
        $factory = $optionsDef->getFactory();
        self::assertIsArray($factory);
        self::assertInstanceOf(Reference::class, $factory[0]);
        self::assertSame('create', $factory[1]);

        // Factory arguments should be rule name and options class
        $args = $optionsDef->getArguments();
        self::assertSame(ComplexityRule::NAME, $args[0]);
        self::assertSame(ComplexityOptions::class, $args[1]);
    }

    #[Test]
    public function injectsOptionsAsArgumentToRule(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsFactory::class)->setSynthetic(true);
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        $ruleDef = $container->getDefinition(ComplexityRule::class);
        $optionsRef = $ruleDef->getArgument('$options');

        self::assertInstanceOf(Reference::class, $optionsRef);
        self::assertSame(ComplexityOptions::class, (string) $optionsRef);
    }

    #[Test]
    public function doesNothingWhenFactoryNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        // No Options registered since factory is missing
        self::assertFalse($container->hasDefinition(ComplexityOptions::class));
    }

    #[Test]
    public function skipsServicesWithNullClass(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsFactory::class)->setSynthetic(true);
        $container->register('rule.null_class')
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        // Should not throw, just skip — no Options services registered
        self::assertSame(
            ['rule.null_class'],
            array_keys($container->findTaggedServiceIds(RuleCompilerPass::TAG)),
        );
    }

    #[Test]
    public function handlesMultipleRules(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsFactory::class)->setSynthetic(true);
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);
        $container->register(ClassCountRule::class)
            ->setClass(ClassCountRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        // Both rules should have options injected
        $complexityDef = $container->getDefinition(ComplexityRule::class);
        self::assertInstanceOf(Reference::class, $complexityDef->getArgument('$options'));

        $classCountDef = $container->getDefinition(ClassCountRule::class);
        self::assertInstanceOf(Reference::class, $classCountDef->getArgument('$options'));
    }

    #[Test]
    public function doesNotReRegisterExistingOptionsService(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsFactory::class)->setSynthetic(true);

        // Pre-register Options with a custom factory
        $container->register(ComplexityOptions::class)
            ->setFactory([new Reference(RuleOptionsFactory::class), 'create'])
            ->setArguments(['custom.name', ComplexityOptions::class]);

        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        // Should keep the existing definition (with 'custom.name')
        $optionsDef = $container->getDefinition(ComplexityOptions::class);
        self::assertSame('custom.name', $optionsDef->getArgument(0));
    }
}
