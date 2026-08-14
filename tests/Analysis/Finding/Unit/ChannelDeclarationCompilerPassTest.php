<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistry;
use Qualimetrix\Infrastructure\Rule\RuleChannelRegistry;
use Qualimetrix\Rules\CodeSmell\GotoRule;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Maintainability\MaintainabilityRule;
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
        $container->register(RuleChannelRegistry::class)
            ->setArguments(['$staticChannelKeysByProducer' => [], '$computedMetricRuleName' => '']);
        $container->register(GotoRule::class)
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register(MaintainabilityRule::class)
            ->setClass(MaintainabilityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        // A rule with no channelDeclarations() at all must contribute nothing
        // and must not break the pass. A dedicated fixture, not a production
        // rule: after this package every production rule declares something,
        // so any production rule used here would break the moment a later
        // package declares it — which is exactly how this exemplar drifted
        // twice already (ClassCountRule, then UnusedPrivateRule).
        $container->register(FixtureRuleWithNoChannelDeclarations::class)
            ->setClass(FixtureRuleWithNoChannelDeclarations::class)
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
        self::assertSame(
            [
                'code-smell.goto' => ['code-smell.goto#code-smell.goto'],
                'maintainability.index' => ['maintainability.index#maintainability.index'],
            ],
            $container->getDefinition(RuleChannelRegistry::class)
                ->getArgument('$staticChannelKeysByProducer'),
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

        self::assertArrayHasKey('complexity.cyclomatic#complexity.cyclomatic.callable', $declarations);
    }

    #[Test]
    public function itAttributesDiagnosticChannelsToTheirProducerRule(): void
    {
        $container = new ContainerBuilder();
        $container->register(ChannelDeclarationRegistry::class)
            ->setArguments(['$staticDeclarations' => []]);
        $container->register(RuleChannelRegistry::class)
            ->setArguments(['$staticChannelKeysByProducer' => [], '$computedMetricRuleName' => '']);
        $container->register(LayerViolationRule::class)
            ->setClass(LayerViolationRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        $channelsByProducer = $container->getDefinition(RuleChannelRegistry::class)
            ->getArgument('$staticChannelKeysByProducer');

        self::assertContains(
            'architecture.coverage#architecture.coverage',
            $channelsByProducer[LayerViolationRule::NAME],
        );
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

/**
 * @internal
 *
 * The "declares nothing" exemplar for {@see ChannelDeclarationCompilerPassTest}.
 * Deliberately a dedicated fixture, not a production rule: every production
 * rule reachable from `src/Rules/**`,
 * `src/Analysis/Policy/Architecture/LayerViolation/*Rule.php`,
 * `src/Analysis/Evidence/CircularDependency/*Rule.php`, and
 * `src/Analysis/Evidence/Duplication/*Rule.php` now declares a channel under
 * ADR 0017, so pointing this test at
 * one would break again the moment a future package declared it — which is
 * exactly how this exemplar drifted twice already (`ClassCountRule`, then
 * `UnusedPrivateRule`). A fixture with no production meaning cannot drift
 * that way.
 */
final class FixtureRuleWithNoChannelDeclarations implements RuleInterface
{
    public function getName(): string
    {
        return 'fixture.no-channel-declarations';
    }

    public function getDescription(): string
    {
        return 'Fixture rule with no channelDeclarations() method, for the compiler pass "declares nothing" case.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::CodeSmell;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        return [];
    }

    /**
     * @return class-string<RuleOptionsInterface>
     */
    public static function getOptionsClass(): string
    {
        return FixtureOptionsWithNoChannelDeclarations::class;
    }
}

/**
 * @internal
 *
 * Minimal {@see RuleOptionsInterface} for {@see FixtureRuleWithNoChannelDeclarations}.
 * Never actually invoked by this test — the compiler pass never calls
 * `getOptionsClass()` — but the return type must be a real class-string.
 */
final class FixtureOptionsWithNoChannelDeclarations implements RuleOptionsInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }
}
