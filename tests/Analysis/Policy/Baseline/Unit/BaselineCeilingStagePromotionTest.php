<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Filter\ViolationFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Filter\GroupCeilingVerdict;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\ViolationFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\CeilingStageFixtures;

/**
 * ADR 0017 — a *measured* breach is reported at Error, carries the level it was
 * accepted at, and reports every member of its group.
 *
 * The fail-safe half of the same rule lives in
 * {@see BaselineCeilingStageFailSafeTest}; the pair matters more than either
 * half, since promotion without scoping turns one hand-edited line into a
 * red build and scoping without promotion leaves every Warning-severity
 * channel free to grow behind a baseline.
 */
#[CoversClass(BaselineCeilingStage::class)]
#[CoversClass(GroupCeilingVerdict::class)]
final class BaselineCeilingStagePromotionTest extends TestCase
{
    use CeilingStageFixtures;

    private const string DUPLICATION = 'duplication.code-duplication';

    #[Test]
    public function itIdentifiesItselfAsTheBaselineStage(): void
    {
        $stage = self::stageOver(self::baselineOf([]));

        self::assertSame(ViolationFilterStage::Baseline, $stage->stage());
        self::assertSame(ViolationFilterStage::Baseline, $stage->apply([])->stage);
    }

    #[Test]
    public function itPromotesEveryMemberOfAMeasuredBreachAndNamesTheAcceptedMagnitudes(): void
    {
        $stage = self::stageOver(self::baselineOf([
            self::magnitudeEntry(self::duplicationFinding(1.0), [40, 100]),
        ]));

        $reported = $stage->apply([
            self::duplicationFinding(60, 1),
            self::duplicationFinding(100, 2),
        ])->violations;

        self::assertSame([Severity::Error, Severity::Error], self::severitiesOf($reported));

        foreach ($reported as $violation) {
            self::assertNotNull($violation->acceptedLevel);
            self::assertSame([40.0, 100.0], $violation->acceptedLevel->magnitudes);
            self::assertSame(2, $violation->acceptedLevel->count);
            self::assertSame('40, 100', $violation->acceptedLevel->describe());
        }
    }

    /**
     * ADR 0017: the design cannot tell which member is new, so a breach reports
     * the whole group. Loud on occurrence channels, and pinned so it is not
     * silently "fixed" into reporting one.
     */
    #[Test]
    public function itReportsFourErrorsWhenAGroupOfFourExceedsACountOfThree(): void
    {
        $goto = ViolationFactory::occurrence(self::someFile());
        $stage = self::stageOver(self::baselineOf([self::occurrenceEntry($goto, 3)]));

        $reported = $stage->apply([$goto, $goto, $goto, $goto])->violations;

        self::assertCount(4, $reported);
        self::assertSame(
            [Severity::Error, Severity::Error, Severity::Error, Severity::Error],
            self::severitiesOf($reported),
        );
        self::assertNotNull($reported[0]->acceptedLevel);
        self::assertNull($reported[0]->acceptedLevel->magnitudes, 'an occurrence channel accepts a count, not a value');
        self::assertSame('3 occurrences', $reported[0]->acceptedLevel->describe());
    }

    #[Test]
    public function itChangesNothingButTheSeverityAndTheAcceptedLevel(): void
    {
        $original = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 16);
        $recorded = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($recorded, [15])]));
        $promoted = $stage->apply([$original])->violations[0];

        self::assertSame($original->location, $promoted->location);
        self::assertSame($original->symbolPath, $promoted->symbolPath);
        self::assertSame($original->ruleName, $promoted->ruleName);
        self::assertSame($original->violationCode, $promoted->violationCode);
        self::assertSame($original->message, $promoted->message);
        self::assertSame($original->metricValue, $promoted->metricValue);
        self::assertSame($original->threshold, $promoted->threshold);
        self::assertSame($original->level, $promoted->level);
        self::assertSame($original->relatedLocations, $promoted->relatedLocations);
        self::assertSame($original->recommendation, $promoted->recommendation);
        self::assertSame($original->dependencyTarget, $promoted->dependencyTarget);
        self::assertSame($original->dependencyType, $promoted->dependencyType);
        self::assertSame(Severity::Error, $promoted->severity);
    }

    /**
     * Three verdicts interleaved in one run: the survivors must come out in
     * the order they went in, or a report would reorder itself the moment a
     * baseline is supplied.
     */
    #[Test]
    public function itPreservesTheInputOrderAcrossMixedVerdicts(): void
    {
        $accepted = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'accepted'), 15);
        $breached = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'breached'), 20);
        $unbounded = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'fresh'), 30);

        $stage = self::stageOver(self::baselineOf([
            self::magnitudeEntry($accepted, [15]),
            self::magnitudeEntry(
                ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'breached'), 19),
                [19],
            ),
        ]));

        $result = $stage->apply([$unbounded, $accepted, $breached]);

        self::assertSame(
            ['callable:App\Foo::fresh', 'callable:App\Foo::breached'],
            array_map(
                static fn(Violation $violation): string => $violation->symbolPath->toCanonical(),
                $result->violations,
            ),
        );
        self::assertSame([Severity::Warning, Severity::Error], self::severitiesOf($result->violations));
        self::assertSame([$accepted], $result->removed);
        self::assertSame(1, $result->removedCount());
    }

    /**
     * ADR 0017 zero tolerance is only sound if both sides pass through the
     * same normalisation. The stored side is rounded by
     * {@see BaselineEntry}'s constructor; this pins that the recomputed side
     * is rounded too.
     *
     * Without the normalising call the raw `15.0000004` is strictly worse
     * than the stored `15.0` and this group breaches at Error, on code that
     * did not change.
     */
    #[Test]
    public function itNormalizesTheRecomputedMagnitudeBeforeComparingIt(): void
    {
        $belowThePrecision = 15.0000004;
        self::assertSame(15.0, BaselineEntry::normalizeMagnitude($belowThePrecision));

        $finding = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), $belowThePrecision);
        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($finding, [$belowThePrecision])]));

        self::assertSame([], $stage->apply([$finding])->violations);
    }

    #[Test]
    public function itLeavesAnEntryOffTheStaleListWhenItsGroupWasMeasured(): void
    {
        $finding = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($finding, [15])]));

        self::assertSame([], $stage->judgeAll([$finding])->staleEntries);
    }

    /**
     * Staleness is keyed on the identity, so a group of several findings
     * answers for its one entry exactly once. Counting findings instead
     * would leave a two-member group's entry looking half-measured.
     */
    #[Test]
    public function itLeavesAMultiMemberGroupsEntryOffTheStaleList(): void
    {
        $first = self::duplicationFinding(100);
        $second = self::duplicationFinding(40, line: 80);

        $stage = self::stageOver(self::baselineOf([self::magnitudeEntry($first, [100, 40])]));

        self::assertSame([], $stage->judgeAll([$first, $second])->staleEntries);
    }

    #[Test]
    public function itListsAnEntryWhoseIdentityDidNotAppearAtAll(): void
    {
        $finding = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $entry = self::magnitudeEntry($finding, [15]);

        $stage = self::stageOver(self::baselineOf([$entry]));

        self::assertSame([$entry], $stage->judgeAll([])->staleEntries);
    }

    private static function duplicationFinding(int|float $magnitude, int $line = 1): Violation
    {
        return self::findingOn(self::DUPLICATION, self::DUPLICATION, self::someFile(), $magnitude, $line);
    }

    private static function someFile(): SymbolPath
    {
        return SymbolPath::forFile(RelativePath::fromString('src/Legacy/dup.php'));
    }
}
