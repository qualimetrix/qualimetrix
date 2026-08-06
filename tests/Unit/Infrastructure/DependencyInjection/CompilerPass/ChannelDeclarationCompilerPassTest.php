<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistry;
use Qualimetrix\Rules\CodeSmell\GotoRule;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Maintainability\MaintainabilityRule;
use Qualimetrix\Rules\Size\ClassCountRule;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(ChannelDeclarationCompilerPass::class)]
final class ChannelDeclarationCompilerPassTest extends TestCase
{
    #[Test]
    public function itCollectsDeclarationsFromEveryTaggedRuleIntoTheRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->register(ChannelDeclarationRegistry::class)
            ->setArguments(['$staticDeclarations' => []]);
        $container->register(GotoRule::class)
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register(MaintainabilityRule::class)
            ->setClass(MaintainabilityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        // A rule with no channelDeclarations() at all must contribute nothing
        // and must not break the pass.
        $container->register(ClassCountRule::class)
            ->setClass(ClassCountRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        $definition = $container->getDefinition(ChannelDeclarationRegistry::class);
        /** @var array<string, ChannelDeclaration> $declarations */
        $declarations = $definition->getArgument('$staticDeclarations');

        self::assertSame(
            ['code-smell.goto#code-smell.goto', 'maintainability.index#maintainability.index'],
            array_keys($declarations),
        );
        self::assertEquals(
            ChannelDeclaration::occurrence(),
            $declarations['code-smell.goto#code-smell.goto'],
        );
        self::assertEquals(
            ChannelDeclaration::magnitude(WorseDirection::Lower),
            $declarations['maintainability.index#maintainability.index'],
        );
    }

    #[Test]
    public function itPairsAViolationCodeWithTheDeclaringRulesOwnName(): void
    {
        $container = new ContainerBuilder();
        $container->register(ChannelDeclarationRegistry::class)
            ->setArguments(['$staticDeclarations' => []]);
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        $declarations = $container->getDefinition(ChannelDeclarationRegistry::class)
            ->getArgument('$staticDeclarations');

        self::assertArrayHasKey('complexity.cyclomatic#complexity.cyclomatic.method', $declarations);
    }

    #[Test]
    public function itDoesNothingWhenTheRegistryIsNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(GotoRule::class)
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        self::assertFalse($container->hasDefinition(ChannelDeclarationRegistry::class));
    }

    #[Test]
    public function itSkipsServicesWithNullClass(): void
    {
        $container = new ContainerBuilder();
        $container->register(ChannelDeclarationRegistry::class)
            ->setArguments(['$staticDeclarations' => []]);
        $container->register('rule.null_class')
            ->addTag(RuleRegistryCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        $declarations = $container->getDefinition(ChannelDeclarationRegistry::class)
            ->getArgument('$staticDeclarations');

        self::assertSame([], $declarations);
    }

    #[Test]
    public function itThrowsOnAChannelDeclaredByTwoDifferentRuleServices(): void
    {
        $container = new ContainerBuilder();
        $container->register(ChannelDeclarationRegistry::class)
            ->setArguments(['$staticDeclarations' => []]);
        $container->register('rule.goto_1')
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register('rule.goto_2')
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Duplicate channel declaration for "code-smell.goto#code-smell.goto"');

        (new ChannelDeclarationCompilerPass())->process($container);
    }
}
