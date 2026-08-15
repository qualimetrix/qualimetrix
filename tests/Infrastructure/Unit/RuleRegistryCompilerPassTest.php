<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Size\ClassCountRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(RuleRegistryCompilerPass::class)]
final class RuleRegistryCompilerPassTest extends TestCase
{
    #[Test]
    public function itCollectsRuleClassesIntoRegistry(): void
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
        foreach ($ruleClasses as $ruleClass) {
            self::assertTrue(is_a($ruleClass, RuleDefinitionInterface::class, true));
        }
    }

    #[Test]
    public function itDoesNothingWhenRegistryNotRegistered(): void
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
    public function itThrowsOnDuplicateNameConstants(): void
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
    public function itThrowsWhenARegisteredRuleDeclaresNoNameConstant(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleRegistry::class);
        $container->register('rule.nameless')
            ->setClass(FixtureNamelessRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        $pass = new RuleRegistryCompilerPass();

        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a string NAME constant');

        $pass->process($container);
    }

    #[Test]
    public function itSkipsServicesWithNullClass(): void
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

/**
 * A real rule implementation whose class omits the mandatory NAME constant.
 *
 * @internal
 */
final class FixtureNamelessRule implements RuleInterface
{
    public function getName(): string
    {
        return 'fixture.nameless';
    }

    public function getDescription(): string
    {
        return 'Rule fixture without a NAME constant';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Complexity;
    }

    public function requires(): array
    {
        return [];
    }

    public function analyze(AnalysisContext $context): array
    {
        return [];
    }

    public static function getOptionsClass(): string
    {
        return ComplexityOptions::class;
    }
}
