<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Analysis\RuleExecution\RuleExecutor;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use AiMessDetector\Rules\Complexity\ComplexityRule;
use AiMessDetector\Rules\Size\ClassCountRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(RuleCompilerPass::class)]
final class RuleCompilerPassTest extends TestCase
{
    #[Test]
    public function collectsTaggedRulesIntoRuleExecutor(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleExecutor::class);
        $container->register(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);
        $container->register(ClassCountRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(RuleExecutor::class);
        $rules = $definition->getArgument(0);

        self::assertCount(2, $rules);
        self::assertInstanceOf(Reference::class, $rules[0]);
        self::assertInstanceOf(Reference::class, $rules[1]);
    }

    #[Test]
    public function doesNothingWhenExecutorNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(RuleExecutor::class));
    }

    #[Test]
    public function setsEmptyArrayWhenNoTaggedServices(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleExecutor::class);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(RuleExecutor::class);

        self::assertSame([], $definition->getArgument(0));
    }
}
