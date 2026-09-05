<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\Directive\Audit\DirectiveUsage;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveOptions;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveValidator;
use Qualimetrix\Analysis\Policy\Inline\Directive\UnusedDirectiveRule;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
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
#[CoversClass(UnusedDirectiveRule::class)]
final class UnusedDirectiveRuleTest extends TestCase
{
    private const string FILE = 'src/Foo.php';

    /**
     * A rule name is not a channel. Since Ш5c a level is no longer a name, so
     * the rules whose name is not also a channel are the ones emitting several
     * *judgements* — the inline-directive producer among them. Silence here is
     * what the old prefix matcher gave and is the defect being removed.
     *
     * This rule owns the banned channel, so the answer is also where the ban and
     * the advice used to disagree: it enumerated all four channels, one of which
     * no directive may carry.
     */
    #[Test]
    public function itRejectsARuleNameWhereASuppressionMustNameAChannel(): void
    {
        $findings = self::runWithSuppression(InlineDirectivePolicyInterface::PRODUCER_RULE_NAME);

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertStringContainsString('names a rule, not a channel', $findings[0]->message);
        self::assertStringContainsString(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->message,
            'the answer still names the channels of that rule a directive may carry',
        );

        // The list is advice, not an inventory: the author writes what it names.
        // It used to name the one channel no directive may address, so following
        // it produced a directive the next run refuses. See
        // BannedChannelIsNeverSuggestedTest for the sweep over every branch.
        self::assertStringNotContainsString(
            InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
            $findings[0]->message,
            $findings[0]->message,
        );
    }

    #[Test]
    public function itRejectsAMisspelledChannel(): void
    {
        $findings = self::runWithSuppression('coupling.instabilty');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
        self::assertStringContainsString('coupling.instability', $findings[0]->message);
    }

    /**
     * Both spellings reach `annotation.unused-directive`, and no directive may
     * address it: the channel reports the directives that did nothing, so a
     * directive silencing it would hide its own answer. The refusal names the
     * channel rather than the selector, because the group form does not
     * contain it.
     */
    #[Test]
    #[DataProvider('provideSpellingsThatReachTheBannedChannel')]
    public function itRefusesAnExactChannelAndAGroupThatReachTheBannedChannel(string $spelling): void
    {
        $findings = self::runWithSuppression($spelling);

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
        self::assertStringContainsString(
            InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
            $findings[0]->message,
        );
        self::assertStringContainsString('which no directive may silence', $findings[0]->message);
    }

    /** @return iterable<string, array{string}> */
    public static function provideSpellingsThatReachTheBannedChannel(): iterable
    {
        yield 'the exact name' => [InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME];
        yield 'a group that covers it' => ['annotation.*'];
        yield 'the exact name at the level it reports at' => [
            InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME . ':file',
        ];
        yield 'a group that covers it, at that level' => ['annotation.*:file'];
    }

    /**
     * The ban stands after the pair grammar, not before it. `:class` is a
     * level this channel never reports at, and an author who wrote that
     * mistake must read about the level rather than about the ban.
     */
    #[Test]
    public function itStillAnswersAnImpossiblePairAboutTheLevel(): void
    {
        $findings = self::runWithSuppression(
            InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME . ':class',
        );

        self::assertCount(1, $findings);
        self::assertStringContainsString('it does not report at level "class"', $findings[0]->message);
        self::assertStringNotContainsString('no directive may silence', $findings[0]->message);
    }

    /**
     * The three neighbouring channels are configuration errors, and the ban
     * does not reach them: a directive naming one is still accepted, still
     * inert, and still says so.
     */
    #[Test]
    #[DataProvider('provideNeighbouringChannels')]
    public function itAcceptsADirectiveNamingANeighbouringConfigurationErrorChannel(string $channel): void
    {
        self::assertSame([], self::runWithSuppression($channel));
    }

    /** @return iterable<string, array{string}> */
    public static function provideNeighbouringChannels(): iterable
    {
        yield 'unresolved' => [InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME];
        yield 'unsupported threshold' => [InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME];
        yield 'invalid threshold' => [InlineDirectivePolicyInterface::INVALID_THRESHOLD_NAME];
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

    /** The channel's own name is the one spelling that addresses it. */
    #[Test]
    public function itAcceptsAnExactChannelName(): void
    {
        self::assertSame([], self::runWithSuppression('coupling.instability'));
    }

    /**
     * A level is addressed beside the name. Both halves are checked against
     * the run's universe: the level a channel declares is accepted, and one it
     * does not report at is refused rather than left to match nothing.
     */
    #[Test]
    public function itAcceptsADeclaredLevelBesideAChannelNameAndRefusesAnUndeclaredOne(): void
    {
        self::assertSame([], self::runWithSuppression('coupling.instability:namespace'));

        $findings = self::runWithSuppression('coupling.instability:file');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
        self::assertStringContainsString('it does not report at level "file"', $findings[0]->message);
    }

    /** A level outside the vocabulary is refused by the same one point. */
    #[Test]
    public function itRefusesALevelThatIsNotOne(): void
    {
        $findings = self::runWithSuppression('coupling.instability:klass');

        self::assertCount(1, $findings);
        self::assertStringContainsString('names no level after ":"', $findings[0]->message);
    }

    /**
     * The retired `rule#code` spelling is refused **by name**, and the
     * diagnostic names the channel the author should have written instead.
     *
     * The alternative this rules out is silence: the argument pattern still
     * admits "#", so a stale directive parses into a name no producer can have
     * and would otherwise suppress nothing without saying so.
     */
    #[Test]
    public function itRejectsTheRetiredChannelPairSpellingAndSaysWhatToWrite(): void
    {
        $findings = self::runWithSuppression('coupling.instability#coupling.instability.class');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
        self::assertStringContainsString(
            'coupling.instability#coupling.instability.class',
            $findings[0]->message,
            'The authored text must round-trip whole; reporting half of it is the defect.',
        );
        self::assertStringContainsString('Write "coupling.instability.class"', $findings[0]->message);
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
            $findings[0]->code,
        );
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itPointsAThresholdNamingAChannelAtTheProducingRule(): void
    {
        $findings = self::runWithThreshold(InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME);

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
        self::assertStringContainsString(
            \sprintf('is a channel of rule "%s"', InlineDirectivePolicyInterface::PRODUCER_RULE_NAME),
            $findings[0]->message,
        );
    }

    /**
     * A threshold takes no level (ADR 0024 §2). The pair is captured by the
     * grammar so that it is *refused*: truncated to its left half it would
     * silently retune the whole rule, which is worse than either outcome.
     */
    #[Test]
    public function itRefusesALevelOnAThresholdInsteadOfTruncatingIt(): void
    {
        $findings = self::runWithThreshold('coupling.cbo:class');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
        self::assertStringContainsString('addresses a rule at a level', $findings[0]->message);
    }

    /** A threshold is never group-shaped, so neither spelling is accepted. */
    #[Test]
    public function itRejectsGroupAndWildcardThresholds(): void
    {
        self::assertCount(1, self::runWithThreshold('coupling.*'));
        self::assertCount(1, self::runWithThreshold('*'));
    }

    /**
     * Type coverage has been spelled three ways and only the third resolves.
     * Ш4b split the single `design.type-coverage` into three producers, and
     * Ш5e3 moved the aspect to the end of the name; both retired spellings are
     * pinned here, because a directive naming one of them must say so rather
     * than resolve to whichever producer is textually nearest.
     */
    #[Test]
    #[DataProvider('retiredTypeCoverageSpellings')]
    public function itRejectsAThresholdNamingARetiredTypeCoverageSpelling(string $spelling): void
    {
        $findings = self::runWithThreshold($spelling);

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function retiredTypeCoverageSpellings(): iterable
    {
        yield 'before the Ш4b split' => ['design.type-coverage'];
        yield 'before the Ш5e3 rename' => ['design.param-type-coverage'];
    }

    /** One dimension, one rule, one threshold that addresses it. */
    #[Test]
    public function itAcceptsAThresholdOnOneTypeCoverageDimension(): void
    {
        self::assertSame([], self::runWithThreshold('design.type-coverage.param'));
    }

    /**
     * And the rule the three replaced is gone: a threshold naming it is the
     * same mistake as a typo, which is what makes the split addressable
     * rather than silently uniform.
     */
    #[Test]
    public function itRejectsAThresholdNamingTheSplitTypeCoverageRule(): void
    {
        $findings = self::runWithThreshold('design.type-coverage');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
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
            $findings[0]->code,
        );
    }

    /**
     * A threshold written in the retired `rule#code` spelling is refused **by
     * name**, with the name to write instead.
     *
     * The separator was outside the threshold grammar, so the pattern used to
     * stop at it and silently retune the left half — a directive doing
     * something other than what it says, which is worse than either a match or
     * a refusal. `#` is admitted into the grammar for exactly this refusal.
     */
    #[Test]
    public function itRejectsAThresholdInTheRetiredChannelPairForm(): void
    {
        $findings = self::runWithThreshold('coupling.cbo#coupling.cbo.class');

        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
        );
        self::assertStringContainsString('Write "coupling.cbo.class"', $findings[0]->message);
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
            'health.cohesion',
            $universe->staticDeclarations(),
            'If this name ever became a static declaration the case below would stop proving anything.',
        );

        $resolved = self::snapshotFactory()->snapshot(new ResolvedComputedMetricDefinitions([
            new ComputedMetricDefinition(
                name: 'health.cohesion',
                formulas: ['class' => '1'],
                description: 'test',
                levels: [SymbolLevel::Class_],
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
                name: 'computed.team-score',
                formulas: ['class' => '1'],
                description: 'test',
                levels: [SymbolLevel::Class_],
            ),
        ]));
        $withoutMetric = self::snapshotFactory()->snapshot(new ResolvedComputedMetricDefinitions([]));

        self::assertSame([], self::runWithSuppression('computed.team-score', $withMetric));

        $findings = self::runWithSuppression('computed.team-score', $withoutMetric);
        self::assertCount(1, $findings);
        self::assertSame(
            InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
            $findings[0]->code,
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
        self::assertSame([], $policy->auditDirectiveUsage([], LevelActivity::empty()));
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
            $findings[0]->code,
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
            static fn(Finding $finding): string => $finding->code,
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
     * @return list<Finding>
     */
    private static function runWithSuppression(string $authored, ?ChannelUniverseInterface $identity = null): array
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
     * @return list<Finding>
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

    private static function policy(?ChannelUniverseInterface $identity = null): InlineDirectivePolicy
    {
        $universe = $identity ?? self::productionUniverse();

        return new InlineDirectivePolicy(new DirectiveUsage(
            $universe,
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            new RuleOptionsRegistry(),
            self::productionUniverse(),
        ));
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
     * @return list<Finding>
     */
    private static function analyzeFamily(
        InlineDirectiveOptions $options,
        InlineDirectivePolicy $policy,
        ChannelUniverseInterface $identity,
    ): array {
        return [
            ...(new UnusedDirectiveRule($options, $policy))->analyze(self::context()),
            ...(new InlineDirectiveValidator($options, $policy, $identity))->validate(self::context()),
        ];
    }
}
