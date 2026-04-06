<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Size\ClassCountRule;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(RuleRegistryCompilerPass::class)]
final class RuleRegistryCompilerPassTest extends TestCase
{
    #[Test]
    public function collectsRuleClassesIntoRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleRegistry::class);
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register(ClassCountRule::class)
            ->setClass(ClassCountRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        $pass = new RuleRegistryCompilerPass();
        $pass->process($container);

        $registry = $container->getDefinition(RuleRegistry::class);
        $ruleClasses = $registry->getArgument('$ruleClasses');

        self::assertCount(2, $ruleClasses);
        self::assertContains(ComplexityRule::class, $ruleClasses);
        self::assertContains(ClassCountRule::class, $ruleClasses);
    }

    #[Test]
    public function doesNothingWhenRegistryNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        $pass = new RuleRegistryCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(RuleRegistry::class));
    }

    #[Test]
    public function throwsOnDuplicateNameConstants(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleRegistry::class);

        // Register the same rule class twice under different service IDs
        $container->register('rule.complexity_1')
            ->setClass(ComplexityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register('rule.complexity_2')
            ->setClass(ComplexityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        $pass = new RuleRegistryCompilerPass();

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Duplicate rule NAME "complexity.cyclomatic"');

        $pass->process($container);
    }

    #[Test]
    public function skipsServicesWithNullClass(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleRegistry::class);
        $container->register('rule.null_class')
            ->addTag(RuleRegistryCompilerPass::TAG);

        $pass = new RuleRegistryCompilerPass();
        $pass->process($container);

        $registry = $container->getDefinition(RuleRegistry::class);
        $ruleClasses = $registry->getArgument('$ruleClasses');

        self::assertSame([], $ruleClasses);
    }
}
