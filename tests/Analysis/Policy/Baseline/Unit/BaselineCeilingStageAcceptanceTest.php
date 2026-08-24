<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\GodClassOptions;
use Qualimetrix\Analysis\Evidence\Design\GodClassRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Filter\GroupCeilingVerdict;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Tests\Analysis\Finding\Support\FindingFactory;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\CeilingStageFixtures;

/**
 * ADR 0017 acceptance rule, per shape and worked by hand over multi-member
 * groups.
 *
 * The multi-member cases are the reason the rule counts members per severity
 * level instead of pairing them by rank. Each of them is stated as stored
 * vector, current vector, verdict — the arithmetic is short enough to check
 * by reading, which is the point: an implementation that reintroduces rank
 * alignment fails at least two of these in opposite directions.
 */
#[CoversClass(BaselineCeilingStage::class)]
#[CoversClass(GroupCeilingVerdict::class)]
final class BaselineCeilingStageAcceptanceTest extends TestCase
{
    use CeilingStageFixtures;

    private const string DUPLICATION = 'duplication.code-duplication';

    // ---------------------------------------------------------------- shapes

    #[Test]
    public function itAcceptsAHigherIsWorseGroupThatDidNotWorsen(): void
    {
        $finding = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($finding, [15])]));

        self::assertSame([], $stage->apply([$finding])->findings);
    }

    #[Test]
    public function itReportsAHigherIsWorseGroupThatWorsenedByOneStep(): void
    {
        $recorded = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $current = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 16);

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded, [15])]));

        self::assertSame([Severity::Error], self::severitiesOf($stage->apply([$current])->findings));
    }

    /**
     * `maintainability.index` — a smaller number is the worse one, so a
     * group that rose is an improvement and stays accepted.
     */
    #[Test]
    public function itAcceptsALowerIsWorseGroupThatImproved(): void
    {
        $recorded = self::maintainability(40);
        $current = self::maintainability(55);

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded, [40])]));

        self::assertSame([], $stage->apply([$current])->findings);
    }

    #[Test]
    public function itReportsALowerIsWorseGroupThatFell(): void
    {
        $recorded = self::maintainability(40);
        $current = self::maintainability(39);

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded, [40])]));

        self::assertSame([Severity::Error], self::severitiesOf($stage->apply([$current])->findings));
    }

    /**
     * The second `lower` family ADR 0017 names, kept as its own case because a
     * reader generalising from `maintainability.index` alone would be
     * generalising from one channel.
     */
    #[Test]
    public function itAcceptsATypeCoverageGroupThatImproved(): void
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare(
            'design.param-type-coverage#design.param-type-coverage',
            ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_),
        );

        $recorded = self::findingOn('design.param-type-coverage', 'design.param-type-coverage', self::someClass(), 60.0);
        $current = self::findingOn('design.param-type-coverage', 'design.param-type-coverage', self::someClass(), 75.0);

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded, [60.0])]), $declarations);

        self::assertSame([], $stage->apply([$current])->findings);
    }

    /**
     * A computed metric whose definition is `inverted` resolves to
     * `lower is worse` at run time, and the ceiling reads that direction
     * without knowing anything else about the definition.
     */
    #[Test]
    public function itAcceptsAnInvertedComputedMetricGroupThatImprovedAndReportsOneThatFell(): void
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('computed.health#health.overall', ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project));

        $recorded = self::findingOn('computed.health', 'health.overall', self::someClass(), 55.0);
        $improved = self::findingOn('computed.health', 'health.overall', self::someClass(), 61.0);
        $worsened = self::findingOn('computed.health', 'health.overall', self::someClass(), 54.9);

        $baseline = self::baselineOf([self::magnitudeEntry($recorded, [55.0])]);

        self::assertSame([], self::stageOver($baseline, $declarations)->apply([$improved])->findings);
        self::assertSame(
            [Severity::Error],
            self::severitiesOf(self::stageOver($baseline, $declarations)->apply([$worsened])->findings),
        );
    }

    /**
     * A continuous axis: `coupling.distance` is a fraction, and the stored
     * boundary bounds it exactly, with no tolerance beyond the shared
     * six-decimal normalisation.
     */
    #[Test]
    public function itBoundsAContinuousAxisExactly(): void
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('coupling.distance#coupling.distance', ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Namespace_));

        $recorded = self::findingOn('coupling.distance', 'coupling.distance', self::someNamespace(), 0.42);
        $same = self::findingOn('coupling.distance', 'coupling.distance', self::someNamespace(), 0.42);
        $worse = self::findingOn('coupling.distance', 'coupling.distance', self::someNamespace(), 0.421);

        $baseline = self::baselineOf([self::magnitudeEntry($recorded, [0.42])]);

        self::assertSame([], self::stageOver($baseline, $declarations)->apply([$same])->findings);
        self::assertSame(
            [Severity::Error],
            self::severitiesOf(self::stageOver($baseline, $declarations)->apply([$worse])->findings),
        );
    }

    #[Test]
    public function itAcceptsAnOccurrenceGroupNoLargerThanItsStoredCount(): void
    {
        $first = FindingFactory::occurrence(self::someFile());
        $second = FindingFactory::occurrence(self::someFile());

        $stage = self::stageOver(self::baselineOf([self::occurrenceEntry($first, 3)]));

        self::assertSame([], $stage->apply([$first, $second])->findings);
    }

    /**
     * ADR 0017 distinguishes a channel's shape from its numeric value: the 15 `marker` channels report a fixed
     * `1.0`, and reading it as a magnitude would bound them by a constant
     * that never changes. The shape decides, so the group is bounded by its
     * count — and the entry, which carries no magnitudes, is applied rather
     * than treated as a mismatch.
     */
    #[Test]
    public function itDoesNotReadAMarkerChannelsFixedValueAsAMagnitude(): void
    {
        $marker = FindingFactory::occurrence(self::someFile());
        self::assertSame(1.0, $marker->metricValue);

        $withinCount = self::stageOver(self::baselineOf([self::occurrenceEntry($marker, 2)]))
            ->apply([$marker, $marker]);
        $overCount = self::stageOver(self::baselineOf([self::occurrenceEntry($marker, 2)]))
            ->apply([$marker, $marker, $marker]);

        self::assertSame([], $withinCount->findings);
        self::assertCount(3, $overCount->findings);
    }

    /**
     * `coupling.class-rank` reports a real number that is nevertheless not a
     * boundary in any later run's units, so ADR 0017 declares it `occurrence`.
     * The number must be ignored outright: a rank three times worse than the
     * one at capture is still one finding, and one finding is what was
     * accepted.
     */
    #[Test]
    public function itIgnoresTheNumberAnOccurrenceChannelNeverthelessReports(): void
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('coupling.class-rank#coupling.class-rank', ChannelDeclaration::occurrence(SymbolLevel::Class_));

        $recorded = self::findingOn('coupling.class-rank', 'coupling.class-rank', self::someClass(), 0.004);
        $current = self::findingOn('coupling.class-rank', 'coupling.class-rank', self::someClass(), 0.012);

        $stage = self::stageOver(self::baselineOf([self::occurrenceEntry($recorded, 1)]), $declarations);

        self::assertSame([], $stage->apply([$current])->findings);
    }

    /**
     * The reason the edge belongs to the identity: replacing one forbidden
     * dependency with another leaves the count unchanged, and without the
     * edge the swap would be accepted in silence.
     */
    #[Test]
    public function itReportsAnEdgeBearingGroupWhenOneForbiddenEdgeIsSwappedForAnother(): void
    {
        $source = SymbolPath::forClass('App\Web', 'Controller');
        $recorded = FindingFactory::edge($source, SymbolPath::forClass('App\Db', 'Connection'));
        $current = FindingFactory::edge($source, SymbolPath::forClass('App\Db', 'Session'));

        $stage = self::stageOver(self::baselineOf([self::occurrenceEntry($recorded, 1)]));
        $result = $stage->apply([$current]);

        self::assertCount(1, $result->findings);
        self::assertNull($result->findings[0]->acceptedLevel, 'a new identity is not a breach of the old one');
    }

    // ------------------------------------------------------- multi-member

    /**
     * **The case that killed rank alignment.** Stored `[40, 100]`; the
     * 40-line duplicate is deleted and nothing else is touched. Aligning
     * from the best end would compare the surviving `100` against the
     * vacated `40` and fail the build on a pure repair. Counting: at
     * `t = 100` one current member against one stored, accepted.
     */
    #[Test]
    public function itAcceptsAGroupWhoseBestMemberWasRepaired(): void
    {
        $stage = self::duplicationStage([40, 100]);

        self::assertSame([], $stage->apply(self::duplicationGroup([100]))->findings);
    }

    /**
     * ADR 0017 mirror. The survivor grew into the slot the repair vacated
     * and stopped short of the worst magnitude already accepted: at
     * `t = 95` there is one current member and two stored ones. Accepted,
     * and recorded as a limitation rather than hidden.
     */
    #[Test]
    public function itAcceptsASurvivorThatGrewJustShortOfTheVacatedMagnitude(): void
    {
        $stage = self::duplicationStage([40, 100]);

        self::assertSame([], $stage->apply(self::duplicationGroup([95]))->findings);
    }

    /**
     * The other side of ADR 0017: past the worst magnitude ever accepted, at
     * `t = 101` there is one current member and no stored one.
     */
    #[Test]
    public function itReportsASurvivorThatGrewPastTheWorstAcceptedMagnitude(): void
    {
        $stage = self::duplicationStage([40, 100]);

        self::assertSame(
            [Severity::Error],
            self::severitiesOf($stage->apply(self::duplicationGroup([101]))->findings),
        );
    }

    /**
     * The *smaller* block grows: at `t = 60` two current members are at
     * least that bad against one stored. Reported — and this is the case an
     * implementation bounded only by the maximum would miss entirely.
     */
    #[Test]
    public function itReportsWhenTheSmallerOfTwoDuplicateBlocksGrows(): void
    {
        $stage = self::duplicationStage([40, 100]);

        self::assertSame(
            [Severity::Error, Severity::Error],
            self::severitiesOf($stage->apply(self::duplicationGroup([60, 100]))->findings),
        );
    }

    #[Test]
    public function itAcceptsAGroupThatShrank(): void
    {
        $stage = self::duplicationStage([40, 100]);

        self::assertSame([], $stage->apply(self::duplicationGroup([40]))->findings);
    }

    /**
     * Shrinking is not a licence: one member at 110 exceeds every stored
     * magnitude, so `t = 110` finds one current member and zero stored.
     */
    #[Test]
    public function itReportsAGroupThatShrankAndGainedAWorseMemberAtOnce(): void
    {
        $stage = self::duplicationStage([40, 100]);

        self::assertSame(
            [Severity::Error],
            self::severitiesOf($stage->apply(self::duplicationGroup([110]))->findings),
        );
    }

    /**
     * **The case that pins the fold.** ADR 0017 states acceptance as one bullet
     * rather than two because the cumulative rule *subsumes* the count
     * condition; nothing proves the fold is actually present unless a group
     * grows in size while staying below the worst magnitude ever accepted.
     *
     * Stored `[100]`, current `[40, 60]`: every current member is milder
     * than the stored one, so an implementation that pairs members from the
     * worst end and stops at the shorter of the two vectors compares `60`
     * against `100`, finds nothing worse, and accepts a group that doubled.
     * Counting: at `t = 40` two current members are at least that bad
     * against one stored, so the group is reported.
     */
    #[Test]
    public function itReportsAGroupThatGrewInSizeThoughEveryMemberIsMilderThanTheWorstStored(): void
    {
        $stage = self::duplicationStage([100]);

        self::assertSame(
            [Severity::Error, Severity::Error],
            self::severitiesOf($stage->apply(self::duplicationGroup([40, 60]))->findings),
        );
    }

    /**
     * ADR 0017: which member changed is not tracked, so one copy removed and
     * another added at the same magnitude reads as unchanged. Stated as a
     * limitation, pinned here so it is not mistaken for a bug later.
     */
    #[Test]
    public function itAcceptsAGroupWhoseMembersSwappedAtEqualMagnitude(): void
    {
        $recorded = [self::duplicationFinding(50, 1), self::duplicationFinding(50, 2)];
        $current = [self::duplicationFinding(50, 3), self::duplicationFinding(50, 4)];
        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded[0], [50, 50])]));

        self::assertNotSame($recorded[0]->location, $current[0]->location);
        self::assertNotSame($recorded[1]->location, $current[1]->location);
        self::assertSame([], $stage->apply($current)->findings);
    }

    /**
     * ADR 0017: the actual God Class rule reports only its matched-criteria
     * tally. A worsening criterion can therefore change the emitted message
     * without changing the value which the ceiling compares.
     */
    #[Test]
    public function itAcceptsACompoundRuleWhoseNonTallyContextChangedWithoutMovingTheTally(): void
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        foreach (GodClassRule::channelDeclarations() as $channel => $declaration) {
            $declarations->declare($channel, $declaration);
        }

        $recorded = self::godClassFinding(50);
        $current = self::godClassFinding(100);

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded, [3])]), $declarations);

        self::assertSame(
            'God Class detected (3/4 criteria): high WMC (50 >= 47), high LCOM (4 >= 3), low TCC (0.20 < 0.33)',
            $recorded->message,
        );
        self::assertSame(
            'God Class detected (3/4 criteria): high WMC (100 >= 47), high LCOM (4 >= 3), low TCC (0.20 < 0.33)',
            $current->message,
        );
        self::assertNotSame($recorded->message, $current->message);
        self::assertSame(3, $recorded->metricValue);
        self::assertSame(3, $current->metricValue);
        self::assertSame([], $stage->apply([$current])->findings);
    }

    /**
     * ADR 0017: the three project-keyed architecture diagnostics share one
     * symbol, so two findings of one channel form a single group bounded by
     * count alone.
     */
    #[Test]
    public function itFormsOneGroupFromTwoProjectKeyedDiagnosticsOfOneChannel(): void
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('architecture.unreachable-layer#architecture.unreachable-layer', ChannelDeclaration::occurrence(SymbolLevel::Project));

        $first = self::findingOn('architecture.unreachable-layer', 'architecture.unreachable-layer', SymbolPath::forProject(), null);
        $second = self::findingOn('architecture.unreachable-layer', 'architecture.unreachable-layer', SymbolPath::forProject(), null, 2);

        $withinCount = self::stageOver(self::baselineOf([self::occurrenceEntry($first, 2)]), $declarations)
            ->apply([$first, $second]);
        $overCount = self::stageOver(self::baselineOf([self::occurrenceEntry($first, 1)]), $declarations)
            ->apply([$first, $second]);

        self::assertSame([], $withinCount->findings, 'one group, bounded by its count');
        self::assertSame([Severity::Error, Severity::Error], self::severitiesOf($overCount->findings));
    }

    // ------------------------------------- multi-member, lower is worse

    /*
     * ADR 0017 warns that the cumulative rule "reads backwards" on a
     * `lower` channel, and every multi-member case above is `higher`. The
     * three below are the mirror images, worked by hand the same way, with
     * "at least as bad as `t`" reading `<= t`.
     *
     * No `lower` channel emits more than one finding per identity today —
     * `maintainability.index` and the three `design.*-type-coverage` rules are
     * one per class
     * — so these groups are constructed, not observed. That is deliberate:
     * the rule is written over a direction, not over a channel, and nothing
     * in the design confines multi-member groups to `higher`. A channel that
     * ever emits two findings for one symbol would reach arithmetic that no
     * other case here exercises.
     */

    /**
     * The mirror of {@see itAcceptsAGroupWhoseBestMemberWasRepaired()}: on a
     * `lower` channel the *best* member is the largest number. Stored
     * `[40, 70]`, the class scoring 70 is repaired out of the group
     * entirely; at `t = 40` one current member is at least that bad against
     * one stored. Accepted.
     *
     * Aligning by rank from the best end would compare the surviving `40`
     * against the vacated `70` and fail the build for a repair — the same
     * defect as on a `higher` channel, arrived at from the opposite end of
     * the number line.
     */
    #[Test]
    public function itAcceptsALowerIsWorseGroupWhoseBestMemberWasRepaired(): void
    {
        $stage = self::maintainabilityStage([40, 70]);

        self::assertSame([], $stage->apply(self::maintainabilityGroup([40]))->findings);
    }

    /**
     * The other side: the survivor fell past the worst magnitude ever
     * accepted. At `t = 39` there is one current member at least that bad
     * and no stored one, so the group is reported.
     */
    #[Test]
    public function itReportsALowerIsWorseSurvivorThatFellPastTheWorstAcceptedMagnitude(): void
    {
        $stage = self::maintainabilityStage([40, 70]);

        self::assertSame(
            [Severity::Error],
            self::severitiesOf($stage->apply(self::maintainabilityGroup([39]))->findings),
        );
    }

    /**
     * The `lower` mirror of the fold above: stored `[40]`, current
     * `[55, 70]`. Both current members are *better* than the stored one, and
     * the group still doubled — at `t = 70` two current members are at least
     * that bad against one stored, so it is reported. Worst-end pairing over
     * the shorter vector compares `55` against `40`, finds an improvement,
     * and accepts.
     */
    #[Test]
    public function itReportsALowerIsWorseGroupThatGrewInSizeThoughEveryMemberImproved(): void
    {
        $stage = self::maintainabilityStage([40]);

        self::assertSame(
            [Severity::Error, Severity::Error],
            self::severitiesOf($stage->apply(self::maintainabilityGroup([55, 70]))->findings),
        );
    }

    // ------------------------------------------------------------ mode

    #[Test]
    public function itAcceptsAWorsenedGroupWhenTheEntrySaysSuppress(): void
    {
        $recorded = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $current = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 90);

        $stage = self::stageOver(self::baselineOf([
            self::magnitudeEntry($recorded, [15], BaselineEntryMode::Suppress),
        ]));

        self::assertSame([], $stage->apply([$current, $current])->findings);
    }

    /**
     * The same waiver on the other shape, where the only thing there is to
     * exceed is the count. Without a case here `suppress` would be pinned
     * only against magnitudes, and an implementation that read it inside the
     * magnitude comparison alone would pass.
     */
    #[Test]
    public function itAcceptsAnOverCountOccurrenceGroupWhenTheEntrySaysSuppress(): void
    {
        $goto = FindingFactory::occurrence(self::someFile());

        $stage = self::stageOver(self::baselineOf([
            self::occurrenceEntry($goto, 1, BaselineEntryMode::Suppress),
        ]));

        self::assertSame([], $stage->apply([$goto, $goto, $goto])->findings);
    }

    // ------------------------------------------------------------ helpers

    /**
     * A ceiling holding one `duplication.code-duplication` entry over a
     * file, captured at the given block lengths.
     *
     * @param list<int|float> $storedMagnitudes
     */
    private static function duplicationStage(array $storedMagnitudes): BaselineCeilingStage
    {
        $member = self::duplicationFinding(1.0);

        return self::stageOver(self::baselineOf([self::magnitudeEntry($member, $storedMagnitudes)]));
    }

    /**
     * @param list<int|float> $magnitudes
     *
     * @return list<Finding>
     */
    private static function duplicationGroup(array $magnitudes): array
    {
        $group = [];

        foreach ($magnitudes as $index => $magnitude) {
            $group[] = self::duplicationFinding($magnitude, $index + 1);
        }

        return $group;
    }

    private static function duplicationFinding(int|float $magnitude, int $line = 1): Finding
    {
        return self::findingOn(self::DUPLICATION, self::DUPLICATION, self::someFile(), $magnitude, $line);
    }

    /**
     * A ceiling holding one `maintainability.index` entry over a class,
     * captured at the given index values — the `lower` counterpart of
     * {@see duplicationStage()}.
     *
     * @param list<int|float> $storedMagnitudes
     */
    private static function maintainabilityStage(array $storedMagnitudes): BaselineCeilingStage
    {
        $member = self::maintainability(1.0);

        return self::stageOver(self::baselineOf([self::magnitudeEntry($member, $storedMagnitudes)]));
    }

    /**
     * @param list<int|float> $magnitudes
     *
     * @return list<Finding>
     */
    private static function maintainabilityGroup(array $magnitudes): array
    {
        $group = [];

        foreach ($magnitudes as $index => $magnitude) {
            $group[] = self::maintainability($magnitude, $index + 1);
        }

        return $group;
    }

    private static function maintainability(int|float $magnitude, int $line = 1): Finding
    {
        return self::findingOn(
            'maintainability.index',
            'maintainability.index.class',
            self::someClass(),
            $magnitude,
            $line,
        );
    }

    private static function someClass(): SymbolPath
    {
        return SymbolPath::forClass('App\Service', 'OrderService');
    }

    private static function godClassFinding(int $wmc): Finding
    {
        $symbolPath = self::someClass();
        $classInfo = new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($symbolPath, RelativePath::fromString('src/OrderService.php'), DeclarationOrdinal::fromRank(0))),
            RelativePath::fromString('src/OrderService.php'),
            10,
        );
        $metrics = (new MetricBag())
            ->with('wmc', $wmc)
            ->with('lcom', 4)
            ->with('tcc', 0.2)
            ->with('classLoc', 100)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('all')
            ->willReturnCallback(static fn(SymbolType $type): array => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')->willReturn($metrics);

        $findings = (new GodClassRule(new GodClassOptions()))->analyze(new AnalysisContext($repository));

        self::assertCount(1, $findings);

        return $findings[0];
    }

    private static function someNamespace(): SymbolPath
    {
        return SymbolPath::forNamespace('App\Service');
    }

    private static function someFile(): SymbolPath
    {
        return SymbolPath::forFile(RelativePath::fromString('src/Legacy/dup.php'));
    }
}
