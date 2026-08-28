<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityRule;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleFamily;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConfigurationValidatorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(ChannelDeclarationCompilerPass::class)]
final class ChannelDeclarationCompilerPassTest extends TestCase
{
    #[Test]
    public function itCollectsDeclarationsFromEveryTaggedRuleIntoTheRegistry(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
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

        $definition = $container->getDefinition(ChannelUniverse::class);
        /** @var array<string, ChannelDeclaration> $declarations */
        $declarations = $definition->getArgument('$staticDeclarations');

        self::assertSame(
            ['code-smell.goto', 'maintainability.index'],
            array_keys($declarations),
        );
        self::assertEquals(
            ChannelDeclaration::occurrence(SymbolLevel::Callable),
            $declarations['code-smell.goto'],
        );
        self::assertEquals(
            ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Callable),
            $declarations['maintainability.index'],
        );
        self::assertSame(
            [
                'code-smell.goto' => ['code-smell.goto'],
                'maintainability.index' => ['maintainability.index'],
            ],
            $container->getDefinition(ChannelUniverse::class)
                ->getArgument('$staticChannelKeysByProducer'),
        );
    }

    #[Test]
    public function itPairsACodeWithTheDeclaringRulesOwnName(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        $declarations = $container->getDefinition(ChannelUniverse::class)
            ->getArgument('$staticDeclarations');

        self::assertArrayHasKey('complexity.cyclomatic', $declarations);
    }

    #[Test]
    public function itAttributesDiagnosticChannelsToTheirProducerRule(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register(LayerViolationRule::class)
            ->setClass(LayerViolationRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register(LayerDeclarationValidator::class)
            ->setClass(LayerDeclarationValidator::class)
            ->addTag(ConfigurationValidatorCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        $channelsByProducer = $container->getDefinition(ChannelUniverse::class)
            ->getArgument('$staticChannelKeysByProducer');

        self::assertContains(
            'architecture.coverage',
            $channelsByProducer[LayerViolationRule::NAME],
        );
    }

    /**
     * `architecture.coverage` is emitted by {@see LayerDeclarationValidator}
     * under its own identity, distinct from the producer rule's `NAME`
     * (`architecture.layer-violation`) — it inherits that rule's declared
     * `REMEDIATION_MINUTES` rather than needing a constant of its own on a
     * class that does not exist.
     */
    #[Test]
    public function itAttributesRemediationMinutesToADiagnosticsOwnChannelName(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register(LayerViolationRule::class)
            ->setClass(LayerViolationRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register(LayerDeclarationValidator::class)
            ->setClass(LayerDeclarationValidator::class)
            ->addTag(ConfigurationValidatorCompilerPass::TAG);
        $container->register(RemediationTimeRegistry::class)
            ->setClass(RemediationTimeRegistry::class)
            ->setArguments(['$declarations' => null, '$minutesByRule' => []]);

        (new ChannelDeclarationCompilerPass())->process($container);

        $minutesByRule = $container->getDefinition(RemediationTimeRegistry::class)
            ->getArgument('$minutesByRule');

        self::assertSame(LayerViolationRule::REMEDIATION_MINUTES, $minutesByRule[LayerViolationRule::NAME]);
        self::assertSame(LayerViolationRule::REMEDIATION_MINUTES, $minutesByRule['architecture.coverage']);
    }

    #[Test]
    public function itDoesNothingWhenTheRegistryIsNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(GotoRule::class)
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        self::assertFalse($container->hasDefinition(ChannelUniverse::class));
    }

    /**
     * Refused rather than skipped: a producer that contributes nothing to the
     * universe and says so nowhere is the one integrity failure in this pass
     * that used to be silent.
     */
    #[Test]
    public function itThrowsOnATaggedServiceWithNoClass(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register('rule.null_class')
            ->addTag(RuleRegistryCompilerPass::TAG);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('names no class');

        (new ChannelDeclarationCompilerPass())->process($container);
    }

    #[Test]
    public function itThrowsOnAChannelDeclaredByTwoDifferentRuleServices(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register('rule.goto_1')
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register('rule.goto_2')
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Duplicate channel declaration for "code-smell.goto"');

        (new ChannelDeclarationCompilerPass())->process($container);
    }

    /**
     * ADR 0031 / Р3: shape moved off {@see ChannelDeclaration} onto the
     * producer. Registry assembly is the one place left that can catch a
     * producer whose declared {@see ChannelShape} disagrees with the
     * direction its own channel carries.
     */
    #[Test]
    public function itThrowsWhenAProducersDeclaredShapeDisagreesWithItsChannelDirection(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register(FixtureRuleWithShapeMismatch::class)
            ->setClass(FixtureRuleWithShapeMismatch::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('carries no direction, but producer "fixture.shape-mismatch" declares shape "magnitude"');

        (new ChannelDeclarationCompilerPass())->process($container);
    }

    /**
     * The display family is derived from a producer's name rather than
     * declared beside it, so a name with no first segment would reach
     * `qmx rules` as an empty group heading. Refused at container build
     * instead — the listing has no way to ask about a producer it is already
     * printing.
     */
    #[Test]
    public function itThrowsWhenAProducerNameIsMalformed(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register(FixtureRuleWithoutAFamily::class)
            ->setClass(FixtureRuleWithoutAFamily::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Producer ".orphan" is not a well-formed name');

        (new ChannelDeclarationCompilerPass())->process($container);
    }

    /**
     * A validator borrows its producer rule's name (ADR 0030); ADR 0031 adds
     * that the two must also agree on what their shared producer's findings
     * mean for baseline purposes.
     */
    #[Test]
    public function itThrowsWhenTwoClassesUnderOneProducerNameDeclareDifferentShapes(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register(FixtureRuleForShapeAgreement::class)
            ->setClass(FixtureRuleForShapeAgreement::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register(FixtureValidatorWithDisagreeingShape::class)
            ->setClass(FixtureValidatorWithDisagreeingShape::class)
            ->addTag(ConfigurationValidatorCompilerPass::TAG);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('declares shape "magnitude" for producer "fixture.shape-agreement", but the rule declares "occurrence"');

        (new ChannelDeclarationCompilerPass())->process($container);
    }

    #[Test]
    public function itRecordsEveryTaggedRuleNameEvenWhenItDeclaresNoChannel(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register(GotoRule::class)
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register(FixtureRuleWithNoChannelDeclarations::class)
            ->setClass(FixtureRuleWithNoChannelDeclarations::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        $support = $container->getDefinition(ChannelUniverse::class)
            ->getArgument('$thresholdOverrideSupportByRule');

        self::assertSame(
            ['code-smell.goto' => false, 'fixture.no-channel-declarations' => false],
            $support,
            'A rule name exists whether or not the rule declares channels — computed.health declares none at all.',
        );
    }

    #[Test]
    public function itCarriesTheDeclaredThresholdOverrideSupportRatherThanInferringIt(): void
    {
        $container = new ContainerBuilder();
        self::registerUniverse($container);
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);
        $container->register(GotoRule::class)
            ->setClass(GotoRule::class)
            ->addTag(RuleRegistryCompilerPass::TAG);

        (new ChannelDeclarationCompilerPass())->process($container);

        $support = $container->getDefinition(ChannelUniverse::class)
            ->getArgument('$thresholdOverrideSupportByRule');

        self::assertTrue($support[ComplexityRule::NAME]);
        self::assertFalse($support[GotoRule::NAME]);
    }

    private static function registerUniverse(ContainerBuilder $container): void
    {
        $container->register(ChannelUniverse::class)
            ->setArguments([
                '$staticDeclarations' => [],
                '$staticChannelKeysByProducer' => [],
                '$thresholdOverrideSupportByRule' => [],
                '$computedMetricRuleName' => '',
            ]);
    }
}

/**
 * @internal
 *
 * The "declares nothing" exemplar for {@see ChannelDeclarationCompilerPassTest}.
 * Deliberately a dedicated fixture, not a production rule: every production
 * rule reachable from the explicit capability roots,
 * including `src/Analysis/Policy/Architecture/LayerViolation/*Rule.php`,
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
    public const string NAME = 'fixture.no-channel-declarations';

    public const string DOCS_PAGE = 'rules/code-smell.md';

    public const int REMEDIATION_MINUTES = 15;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Fixture rule with no channelDeclarations() method, for the compiler pass "declares nothing" case.';
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Finding>
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
 * Declares one `magnitude` channel while claiming `occurrence` — the
 * per-channel half of the shape guarantee
 * {@see ChannelDeclarationCompilerPassTest::itThrowsWhenAProducersDeclaredShapeDisagreesWithItsChannelDirection()}
 * exercises.
 */
final class FixtureRuleWithShapeMismatch implements RuleInterface
{
    public const string NAME = 'fixture.shape-mismatch';

    public const string DOCS_PAGE = 'rules/code-smell.md';

    public const int REMEDIATION_MINUTES = 5;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Fixture rule whose declared shape disagrees with its channel.';
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Magnitude;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Finding>
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

    /**
     * Occurrence — no direction — while {@see shape()} above claims
     * `magnitude`. Registry assembly must refuse this, not silently trust
     * the constant.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Project)];
    }
}

/**
 * @internal
 *
 * A rule and a validator that share one producer name but disagree on
 * {@see ConfigurationValidatorInterface::shape()} —
 * {@see ChannelDeclarationCompilerPassTest::itThrowsWhenTwoClassesUnderOneProducerNameDeclareDifferentShapes()}.
 */
final class FixtureRuleForShapeAgreement implements RuleInterface
{
    public const string NAME = 'fixture.shape-agreement';

    public const string DOCS_PAGE = 'rules/code-smell.md';

    public const int REMEDIATION_MINUTES = 5;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Fixture rule half of a mismatched producer pair.';
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Finding>
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

    /**
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Project)];
    }
}

/** @internal Declares `magnitude`, disagreeing with {@see FixtureRuleForShapeAgreement::shape()}. */
final class FixtureValidatorWithDisagreeingShape implements ConfigurationValidatorInterface
{
    public static function producerRuleName(): string
    {
        return FixtureRuleForShapeAgreement::NAME;
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Magnitude;
    }

    /**
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            'fixture.diagnostic' => ChannelDeclaration::magnitude(
                WorseDirection::Higher,
                SymbolLevel::Project,
            ),
        ];
    }

    public function validate(AnalysisContext $context): array
    {
        return [];
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

/**
 * @internal
 *
 * A name whose first dot-separated segment is empty — the one shape
 * {@see RuleFamily} cannot answer for, and therefore the shape
 * {@see ChannelDeclarationCompilerPassTest::itThrowsWhenAProducerNameIsMalformed()}
 * requires the container build to refuse.
 */
final class FixtureRuleWithoutAFamily implements RuleInterface
{
    public const string NAME = '.orphan';

    public const string DOCS_PAGE = 'rules/code-smell.md';

    public const int REMEDIATION_MINUTES = 15;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Fixture rule whose name has no non-empty first segment.';
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Finding>
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
