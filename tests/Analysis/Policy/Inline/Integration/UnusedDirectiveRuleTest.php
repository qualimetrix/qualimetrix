<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveOptions;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveRule;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveValidator;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;

/**
 * The loud half of the inline-directive report, against the real channel
 * universe of a resolved configuration.
 *
 * Every case here is written so that it fails on the implementation it is
 * meant to rule out, not merely passes on the one that is wanted:
 * {@see itAcceptsAComputedMetricChannelThatOnlyTheResolvedConfigurationKnows}
 * fails outright against a check written over the static declarations, and the
 * rejection cases fail if the directive is left inert.
 */
#[CoversClass(InlineDirectiveRule::class)]
final class InlineDirectiveRuleTest extends TestCase
{
    private const string FILE = 'src/Foo.php';

    /**
     * A rule name is not a channel, and the rules whose name is not also a
     * channel are exactly the multi-channel ones. Silence here is what the
     * old prefix matcher gave and is the defect being removed.
     */
    #[Test]
    public function itRejectsARuleNameWhereASuppressionMustNameAChannel(): void
    {
        $findings = self::runWithSuppression('coupling.instability');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->violationCode,
        );
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertStringContainsString('names a rule, not a channel', $findings[0]->message);
        self::assertStringContainsString('coupling.instability.class', $findings[0]->message);
    }

    #[Test]
    public function itRejectsAMisspelledChannel(): void
    {
        $findings = self::runWithSuppression('coupling.instabilty');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->violationCode,
        );
        self::assertStringContainsString('coupling.instability', $findings[0]->message);
    }

    #[Test]
    public function itAcceptsAnExactChannelAndAGroupThatCoversSomething(): void
    {
        self::assertSame([], self::runWithSuppression('coupling.instability.class'));
        self::assertSame([], self::runWithSuppression('coupling.instability.*'));
    }

    /**
     * A group form over a channel with no descendants covers nothing, and
     * "covers nothing" is the whole test of addressability.
     */
    #[Test]
    public function itRejectsAGroupFormThatCoversNoChannel(): void
    {
        $findings = self::runWithSuppression('code-smell.eval.*');

        self::assertCount(1, $findings);
        self::assertStringContainsString('has no channels below it', $findings[0]->message);
    }

    /** The explicit pair is a legitimate spelling of a channel that exists. */
    #[Test]
    public function itAcceptsAnExplicitChannelPair(): void
    {
        self::assertSame([], self::runWithSuppression('coupling.instability#coupling.instability.class'));
    }

    /**
     * A pair whose halves belong to different channels is refused, and the
     * diagnostic names the spelling the author should have written — the
     * useful answer when both halves are exact is *which half* is wrong.
     *
     * The alternative this rules out is the one that shipped: the argument
     * pattern stopped at "#", so the second half was silently dropped and the
     * directive was judged against the truncated first half.
     */
    #[Test]
    public function itRejectsAnExplicitPairWhoseHalvesDoNotBelongTogether(): void
    {
        $findings = self::runWithSuppression('complexity.cyclomatic#coupling.instability.class');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->violationCode,
        );
        self::assertStringContainsString(
            'complexity.cyclomatic#coupling.instability.class',
            $findings[0]->message,
            'The authored text must round-trip whole; reporting half of it is the defect.',
        );
        self::assertStringContainsString('coupling.instability#coupling.instability.class', $findings[0]->message);
    }

    /**
     * Prose where a channel was expected: the message has to teach the
     * grammar rather than pretend the author mistyped a channel name, because
     * on the file form the channel is optional and the first word of a reason
     * is indistinguishable from one.
     */
    #[Test]
    public function itExplainsTheGrammarWhenAFileFormCarriesABareReason(): void
    {
        $findings = self::runWithSuppression('Generated');

        self::assertCount(1, $findings);
        self::assertStringContainsString('addresses no channel', $findings[0]->message);
        self::assertStringContainsString('--', $findings[0]->message);
    }

    /**
     * The separator standing alone in the channel position on a form whose
     * channel is mandatory: the message says which forms may omit it.
     */
    #[Test]
    public function itExplainsWhichFormMayOmitTheChannel(): void
    {
        $findings = self::runWithSuppression('--');

        self::assertCount(1, $findings);
        self::assertStringContainsString('Only @qmx-ignore-file may leave the channel out', $findings[0]->message);
    }

    /** The one spelling that filters on nothing keeps working and stays silent. */
    #[Test]
    public function itAcceptsTheNoRuleFilterForm(): void
    {
        self::assertSame([], self::runWithSuppression('*'));
    }

    /**
     * The case the plan calls addressability rather than existence: the name
     * resolves, and the directive still cannot ever do anything.
     */
    #[Test]
    public function itRejectsAThresholdOnARuleThatDeclaresNoOverrideSupport(): void
    {
        $findings = self::runWithThreshold('code-smell.boolean-argument');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME,
            $findings[0]->violationCode,
        );
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itPointsAThresholdNamingAChannelAtTheProducingRule(): void
    {
        $findings = self::runWithThreshold('coupling.cbo.class');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->violationCode,
        );
        self::assertStringContainsString('is a channel of rule "coupling.cbo"', $findings[0]->message);
    }

    /** A threshold is never group-shaped, so neither spelling is accepted. */
    #[Test]
    public function itRejectsGroupAndWildcardThresholds(): void
    {
        self::assertCount(1, self::runWithThreshold('coupling.*'));
        self::assertCount(1, self::runWithThreshold('*'));
    }

    /**
     * The dimensions of `design.type-coverage` are channels, not addressable
     * units: one `withOverride` retunes all three together, so there is no
     * per-dimension threshold for `.param` to have meant.
     */
    #[Test]
    public function itPointsAThresholdNamingATypeCoverageDimensionAtTheRule(): void
    {
        $findings = self::runWithThreshold('design.type-coverage.param');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->violationCode,
        );
        self::assertStringContainsString('is a channel of rule "design.type-coverage"', $findings[0]->message);
    }

    /** The rule this addresses uniformly, and the one the plan's counterexample turns on. */
    #[Test]
    public function itAcceptsAThresholdOnTypeCoverageItself(): void
    {
        self::assertSame([], self::runWithThreshold('design.type-coverage'));
    }

    /**
     * A bare family name is the third spelling that used to retune every rule
     * beneath it. It names no rule, so it is the same mistake as a typo.
     */
    #[Test]
    public function itRejectsAThresholdNamingARuleFamilyRatherThanARule(): void
    {
        $findings = self::runWithThreshold('coupling');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->violationCode,
        );
    }

    #[Test]
    public function itAcceptsAThresholdOnARuleThatDeclaresOverrideSupport(): void
    {
        self::assertSame([], self::runWithThreshold('coupling.cbo'));
    }

    /**
     * The check runs after configuration has resolved, and this is the case
     * that proves it: `health.cohesion` exists only because the run's
     * configuration defines it. The first assertion is the point — the same
     * name is absent from the static declarations, so a check written against
     * them would call this annotation a mistake, and this test would fail.
     */
    #[Test]
    public function itAcceptsAComputedMetricChannelThatOnlyTheResolvedConfigurationKnows(): void
    {
        $universe = self::productionUniverse();

        self::assertArrayNotHasKey(
            'computed.health#health.cohesion',
            $universe->staticDeclarations(),
            'If this name ever became a static declaration the case below would stop proving anything.',
        );

        $resolved = self::snapshotFactory()->snapshot(new ResolvedComputedMetricDefinitions([
            new ComputedMetricDefinition(
                name: 'health.cohesion',
                formulas: ['class' => '1'],
                description: 'test',
                levels: [SymbolType::Class_],
            ),
        ]));

        self::assertSame([], self::runWithSuppression('health.cohesion', $resolved));
    }

    /**
     * The other side of the same coin, and §5's eighth breaking change:
     * dropping a metric from `computed_metrics:` makes every annotation that
     * named it a dangling reference, which is the same mistake as a typo.
     */
    #[Test]
    public function itRejectsADanglingReferenceToAMetricNoLongerConfigured(): void
    {
        $withMetric = self::snapshotFactory()->snapshot(new ResolvedComputedMetricDefinitions([
            new ComputedMetricDefinition(
                name: 'computed.team_score',
                formulas: ['class' => '1'],
                description: 'test',
                levels: [SymbolType::Class_],
            ),
        ]));
        $withoutMetric = self::snapshotFactory()->snapshot(new ResolvedComputedMetricDefinitions([]));

        self::assertSame([], self::runWithSuppression('computed.team_score', $withMetric));

        $findings = self::runWithSuppression('computed.team_score', $withoutMetric);
        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->violationCode,
        );
    }

    /** A disabled rule silences its own report, and nothing else does. */
    #[Test]
    public function itReportsNothingWhenTheOwningRuleIsDisabled(): void
    {
        $policy = self::policy();
        $policy->prepare(
            [self::FILE => [new Suppression('nope.nothing', null, 10, SuppressionType::File)]],
            [],
            [],
        );

        self::assertSame([], self::analyzeFamily(
            new InlineDirectiveOptions(enabled: false),
            $policy,
            self::productionUniverse(),
        ));
        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    /**
     * One annotation on a class is one directive, however many declarations
     * the extractor bound it to.
     *
     * A class docblock is bound to the class and to every method in it, so a
     * single typo on a forty-method class used to print forty-one identical
     * configuration errors — and a configuration error ends the run past
     * `fail_on`, which makes that exactly the report a reader learns to skip.
     *
     * The subject is asserted too: the finding belongs to the file the
     * annotation is written in, not to whichever binding happened to sort
     * first (`Demo\Big::a` for an annotation written on `Demo\Big`).
     */
    #[Test]
    public function itReportsOneFindingPerAuthoredDirectiveNotPerBoundDeclaration(): void
    {
        $identity = self::productionUniverse();
        $policy = self::policy($identity);
        $policy->prepare([self::FILE => self::classDocblockBindings('coupling.instabilty')], [], []);

        $findings = self::analyzeFamily(new InlineDirectiveOptions(), $policy, $identity);

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->violationCode,
        );
        self::assertSame(4, $findings[0]->location->line);
        self::assertSame(
            MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString(self::FILE)))->toCanonical(),
            $findings[0]->subject->toCanonical(),
        );
    }

    /** Two distinct annotations on the same docblock stay two findings. */
    #[Test]
    public function itKeepsDistinctAuthoredDirectivesApart(): void
    {
        $identity = self::productionUniverse();
        $policy = self::policy($identity);
        $policy->prepare(
            [self::FILE => [
                ...self::classDocblockBindings('coupling.instabilty'),
                ...self::classDocblockBindings('complexity.cyclomtic', line: 5),
            ]],
            [],
            [],
        );

        self::assertCount(
            2,
            self::analyzeFamily(new InlineDirectiveOptions(), $policy, $identity),
        );
    }

    /** The same collapse for the two threshold channels. */
    #[Test]
    public function itReportsOneFindingPerAuthoredThresholdDirective(): void
    {
        $identity = self::productionUniverse();
        $policy = self::policy($identity);
        $overrides = [];
        $diagnostics = [];
        foreach (self::boundSubjects() as $subject) {
            $overrides[] = new ThresholdOverride(
                rulePattern: 'code-smell.boolean-argument',
                warning: 5.0,
                error: 5.0,
                line: 12,
                subject: $subject,
                controlScope: ControlScope::Class_,
            );
            $diagnostics[] = new ThresholdDiagnostic(
                line: 13,
                subject: $subject,
                message: '@qmx-threshold complexity.cyclomatic: invalid syntax',
            );
        }
        $policy->prepare([], [self::FILE => $overrides], [self::FILE => $diagnostics]);

        $codes = array_map(
            static fn(Violation $violation): string => $violation->violationCode,
            self::analyzeFamily(new InlineDirectiveOptions(), $policy, $identity),
        );
        sort($codes);

        self::assertSame(
            [
                InlineDirectivePolicyInterface::INVALID_THRESHOLD_NAME,
                InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME,
            ],
            $codes,
        );
    }

    /**
     * What the extractor produces for one class docblock: the class plus
     * every method it governs, all carrying the same authored line and text.
     *
     * @return list<Suppression>
     */
    private static function classDocblockBindings(string $authored, int $line = 4): array
    {
        return array_map(
            static fn(MetricSubject $subject): Suppression => new Suppression(
                $authored,
                'reason',
                $line,
                SuppressionType::Symbol,
                subject: $subject,
                controlScope: ControlScope::Class_,
            ),
            self::boundSubjects(),
        );
    }

    /** @return list<MetricSubject> */
    private static function boundSubjects(): array
    {
        $file = RelativePath::fromString(self::FILE);
        $subjects = [MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('Demo', 'Big'), $file, DeclarationOrdinal::fromRank(0)))];

        foreach (['a', 'b', 'c', 'd', 'e'] as $index => $member) {
            $subjects[] = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('Demo', 'Big', $member), $file, DeclarationOrdinal::fromRank(0)));
        }

        return $subjects;
    }

    /**
     * @return list<Violation>
     */
    private static function runWithSuppression(string $authored, ?ChannelIdentityInterface $identity = null): array
    {
        $identity ??= self::productionUniverse();
        $policy = self::policy($identity);
        $policy->prepare(
            [self::FILE => [new Suppression($authored, 'reason', 10, SuppressionType::File)]],
            [],
            [],
        );

        return self::analyzeFamily(new InlineDirectiveOptions(), $policy, $identity);
    }

    /**
     * @return list<Violation>
     */
    private static function runWithThreshold(string $authored): array
    {
        $identity = self::productionUniverse();
        $policy = self::policy($identity);
        $policy->prepare([], [self::FILE => [new ThresholdOverride(
            rulePattern: $authored,
            warning: 10.0,
            error: 20.0,
            line: 12,
            subject: self::subject(),
            controlScope: ControlScope::Class_,
        )]], []);

        return self::analyzeFamily(new InlineDirectiveOptions(), $policy, $identity);
    }

    private static function policy(?ChannelIdentityInterface $identity = null): InlineDirectivePolicy
    {
        return new InlineDirectivePolicy(
            $identity ?? self::productionUniverse(),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            new RuleOptionsRegistry(),
        );
    }

    private static function context(): AnalysisContext
    {
        return new AnalysisContext(self::createStub(MetricRepositoryInterface::class));
    }

    private static function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString(self::FILE)));
    }

    private static ?RuleChannelSnapshotFactoryInterface $emptyUniverse = null;

    /**
     * The production universe over an empty resolved configuration — the
     * shape every case starts from, and the shape
     * {@see itAcceptsAComputedMetricChannelThatOnlyTheResolvedConfigurationKnows}
     * then adds one configured metric to.
     */
    private static function productionUniverse(): ChannelUniverseInterface
    {
        return self::snapshotFactory()->snapshot(new ResolvedComputedMetricDefinitions([]));
    }

    private static function snapshotFactory(): RuleChannelSnapshotFactoryInterface
    {
        if (self::$emptyUniverse !== null) {
            return self::$emptyUniverse;
        }

        $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
        \assert($universe instanceof RuleChannelSnapshotFactoryInterface);

        return self::$emptyUniverse = $universe;
    }

    /**
     * Both producers of the `annotation.directive` family, in the order the
     * executor runs them: the rule arms the usage report, the validator emits
     * the three directive errors.
     *
     * @return list<Violation>
     */
    private static function analyzeFamily(
        InlineDirectiveOptions $options,
        InlineDirectivePolicy $policy,
        ChannelIdentityInterface $identity,
    ): array {
        return [
            ...(new InlineDirectiveRule($options, $policy))->analyze(self::context()),
            ...(new InlineDirectiveValidator($options, $policy, $identity))->validate(self::context()),
        ];
    }
}
