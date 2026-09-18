<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Size\ClassCountRule;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(RuleCompilerPass::class)]
final class RuleCompilerPassTest extends TestCase
{
    #[Test]
    public function itCollectsTaggedRulesIntoRuleExecution(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleExecution::class);
        $container->register(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);
        $container->register(ClassCountRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(RuleExecution::class);

        self::assertEquals(
            [new Reference(ComplexityRule::class), new Reference(ClassCountRule::class)],
            $definition->getArgument(0),
        );
    }

    #[Test]
    public function itDoesNothingWhenNoConsumerIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(RuleExecution::class));
        self::assertFalse($container->hasDefinition(RulesCommand::class));
    }

    /**
     * The consumers are read off the pass's own declaration rather than named
     * here: a consumer added to that list and not injected into is the defect
     * a hand-written list in this file would be edited past.
     */
    #[Test]
    public function itInjectsIntoEveryConsumerItDeclaresAndIntoNothingElse(): void
    {
        /** @var array<string, int> $consumers */
        $consumers = (new ReflectionClass(RuleCompilerPass::class))->getConstant('CONSUMERS');

        self::assertNotSame([], $consumers, 'The pass declares no consumers, so this case checked nothing.');

        $container = new ContainerBuilder();

        foreach (array_keys($consumers) as $consumerId) {
            $container->register($consumerId);
        }

        // Registered, tagged by nobody and not a declared consumer: the pass
        // must leave it exactly as it found it.
        $container->register(RulesCommand::class);
        $container->register(ComplexityRule::class)->addTag(RuleCompilerPass::TAG);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        foreach ($consumers as $consumerId => $argumentIndex) {
            self::assertEquals(
                [new Reference(ComplexityRule::class)],
                $container->getDefinition($consumerId)->getArgument($argumentIndex),
                $consumerId,
            );
        }

        self::assertSame([], $container->getDefinition(RulesCommand::class)->getArguments());
    }

    #[Test]
    public function itSetsAnEmptyArrayWhenNoRulesAreTagged(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleExecution::class);

        $pass = new RuleCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(RuleExecution::class);

        self::assertSame([], $definition->getArgument(0));
    }
}
