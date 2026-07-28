<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\RuleExecution\RuleExecutor;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Size\ClassCountRule;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(RuleCompilerPass::class)]
final class RuleCompilerPassTest extends TestCase
{
    #[Test]
    public function itCollectsTaggedRulesIntoRuleExecutor(): void
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
    public function itCollectsTaggedRulesIntoRulesCommand(): void
    {
        $container = new ContainerBuilder();
        $container->register(RulesCommand::class)->setArguments([[]]);
        $container->register(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);
        $container->register(ClassCountRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        $rules = $container->getDefinition(RulesCommand::class)->getArgument(0);

        self::assertCount(2, $rules);
        self::assertContainsOnlyInstancesOf(Reference::class, $rules);
    }

    #[Test]
    public function itDoesNothingWhenNoConsumerIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(RuleExecutor::class));
        self::assertFalse($container->hasDefinition(RulesCommand::class));
    }

    #[Test]
    public function itInjectsIntoEveryRegisteredConsumerAtOnce(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleExecutor::class);
        $container->register(RulesCommand::class)->setArguments([[]]);
        $container->register(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        self::assertCount(1, $container->getDefinition(RuleExecutor::class)->getArgument(0));
        self::assertCount(1, $container->getDefinition(RulesCommand::class)->getArgument(0));
    }

    #[Test]
    public function itSetsAnEmptyArrayWhenNoRulesAreTagged(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleExecutor::class);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(RuleExecutor::class);

        self::assertSame([], $definition->getArgument(0));
    }
}
