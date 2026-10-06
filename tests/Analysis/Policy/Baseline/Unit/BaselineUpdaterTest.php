<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdateDisposition;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdateRefusalReason;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdateResult;
use Qualimetrix\Analysis\Policy\Baseline\EntrySelector;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FindingFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubRuleCoverage;

/**
 * ADR 0017 rules for `baseline:update`, exercised through the domain service
 * itself rather than through {@see \Qualimetrix\Analysis\Policy\Baseline\GroupAcceptance}
 * directly — `GroupAcceptanceTest` already pins the primitive; this pins
 * that `BaselineUpdater` actually calls it and does the right thing with the
 * verdict: write, refuse, or leave untouched.
 */
#[CoversClass(BaselineUpdater::class)]
final class BaselineUpdaterTest extends TestCase
{
    /**
     * **The case that killed the per-position rule (ADR 0017).** Stored
     * `[40, 100]` on a `higher` channel, the 40-line duplicate repaired,
     * measured group `[100]`. A per-position rule reads rank 0 growing from
     * 40 to 100 and refuses; the group rule accepts it because `{100}` sits
     * inside `{100, 40}`.
     */
    #[Test]
    public function itAcceptsAndWritesAShrunkGroup(): void
    {
        $symbol = SymbolPath::forFile(RelativePath::fromString('src/Legacy/dup.php'));
        $stored = new BaselineEntry(
            new BaselineIdentity($symbol->toCanonical(), self::duplicationChannel()),
            [40, 100],
            2,
        );

        $current = FindingFactory::magnitude($symbol, 100, 'duplication.clone', 'duplication.clone');

        $result = $this->update(self::baselineOf($stored), [$current], RunScope::fromRecorded(['src']));

        self::assertSame(BaselineUpdateDisposition::Updated, $result->outcomes[0]->disposition);
        self::assertSame([100.0], $result->baseline->entries[0]->magnitudes);
        self::assertSame(1, $result->baseline->entries[0]->count);
        self::assertTrue($result->changed, 'the written entry differs from what was loaded');
    }

    /**
     * **`$changed` reads the payload, not the disposition (ADR 0017).** A
     * measured group that reports exactly what is already stored is
     * `Unchanged`, and the caller deciding whether to touch the file sees
     * `false`.
     */
    #[Test]
    public function itReportsNoChangeWhenAnUpdatedEntryWritesBackTheSamePayload(): void
    {
        $symbol = SymbolPath::forFile(RelativePath::fromString('src/Legacy/dup.php'));
        $stored = new BaselineEntry(
            new BaselineIdentity($symbol->toCanonical(), self::duplicationChannel()),
            [40, 100],
            2,
        );

        $current = [
            FindingFactory::magnitude($symbol, 40, 'duplication.clone', 'duplication.clone'),
            FindingFactory::magnitude($symbol, 100, 'duplication.clone', 'duplication.clone'),
        ];

        $result = $this->update(self::baselineOf($stored), $current, RunScope::fromRecorded(['src']));

        self::assertSame(BaselineUpdateDisposition::Unchanged, $result->outcomes[0]->disposition);
        self::assertFalse($result->changed, 'the measured group reports exactly what was already stored');
    }

    #[Test]
    public function itReportsNoChangeWhenEveryEntryIsSkipped(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $stored = new BaselineEntry(BaselineIdentity::forFinding(FindingFactory::magnitude($symbol, 25)), [25], 1);

        $result = $this->update(self::baselineOf($stored), [], RunScope::fromRecorded(['src']));

        self::assertSame(BaselineUpdateDisposition::Skipped, $result->outcomes[0]->disposition);
        self::assertFalse($result->changed);
    }

    /**
     * **The `lower`-channel count widening that must be refused (ADR 0017).**
     * Stored `[40]`; the measured group is `[55, 70]` — both members
     * individually improved over the one stored value, but the group
     * doubled in size. ADR 0017 cumulative rule catches this without a
     * separate count check: at `t = 70` there are two current members at
     * least that bad and only one stored one.
     */
    #[Test]
    public function itRefusesALowerChannelGroupThatGrewInSizeThoughEveryMemberImproved(): void
    {
        $symbol = SymbolPath::forClass('App', 'Service');
        $stored = new BaselineEntry(
            BaselineIdentity::forFinding(FindingFactory::magnitude(
                $symbol,
                40,
                'maintainability.mi',
                'maintainability.index.class',
            )),
            [40],
            1,
        );

        $current = [
            FindingFactory::magnitude($symbol, 55, 'maintainability.mi', 'maintainability.index.class'),
            FindingFactory::magnitude($symbol, 70, 'maintainability.mi', 'maintainability.index.class'),
        ];

        $result = $this->update(self::baselineOf($stored), $current, RunScope::fromRecorded(['src']));

        self::assertSame(BaselineUpdateDisposition::Refused, $result->outcomes[0]->disposition);
        self::assertSame(BaselineUpdateRefusalReason::Worsened, $result->outcomes[0]->refusalReason);
        self::assertSame([40.0], $result->baseline->entries[0]->magnitudes, 'the stored entry is written back unchanged');
        self::assertSame(1, $result->baseline->entries[0]->count);
    }

    #[Test]
    public function itLeavesAVanishedGroupUntouched(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $stored = new BaselineEntry(BaselineIdentity::forFinding(FindingFactory::magnitude($symbol, 25)), [25], 1);

        $result = $this->update(self::baselineOf($stored), [], RunScope::fromRecorded(['src']));

        self::assertSame(BaselineUpdateDisposition::Skipped, $result->outcomes[0]->disposition);
        self::assertSame($stored, $result->baseline->entries[0], 'the untouched entry is the exact same object, not a rebuilt copy');
    }

    #[Test]
    public function itNeverAddsAnIdentityTheBaselineDidNotAlreadyHold(): void
    {
        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: [], entries: [], exclusions: self::fixtureExclusions());
        $found = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 25);

        $result = $this->update($baseline, [$found], RunScope::fromRecorded(['src']));

        self::assertSame(0, $result->baseline->count());
        self::assertSame([], $result->outcomes);
    }

    #[Test]
    public function itCarriesInertEntriesForwardVerbatim(): void
    {
        $inert = new InertBaselineEntry(
            subjectKey: 'file:src/Legacy.php',
            channelKey: null,
            identity: null,
            selector: EntrySelector::forKey('file:src/Legacy.php'),
            reason: InertEntryReason::Malformed,
            detail: 'entry must be a JSON object',
            raw: 'garbage',
        );

        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [], inertEntries: [$inert], exclusions: self::fixtureExclusions());

        $result = $this->update($baseline, [], RunScope::fromRecorded(['src']));

        self::assertSame([$inert], $result->baseline->inertEntries);
    }

    #[Test]
    public function itPreservesModeOnAWrittenEntry(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $stored = new BaselineEntry(
            BaselineIdentity::forFinding(FindingFactory::magnitude($symbol, 25)),
            [25],
            1,
            BaselineEntryMode::Suppress,
        );

        $current = FindingFactory::magnitude($symbol, 20);

        $result = $this->update(self::baselineOf($stored), [$current], RunScope::fromRecorded(['src']));

        self::assertSame(BaselineEntryMode::Suppress, $result->baseline->entries[0]->mode);
    }

    /**
     * A `mode: suppress` entry takes the same comparison as any other — a
     * worse group must not be written into it, or `update` would become a
     * way to widen an acceptance. What it must not take is the same *word*:
     * the ceiling never compares these numbers at `check` time, so nothing
     * the user can observe worsened and "worsened" would send them hunting a
     * red build that does not exist.
     */
    #[Test]
    public function itNamesASuppressedEntrysRefusalAfterTheSuppressionRatherThanAWorsening(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $stored = new BaselineEntry(
            BaselineIdentity::forFinding(FindingFactory::magnitude($symbol, 25)),
            [25],
            1,
            BaselineEntryMode::Suppress,
        );

        $result = $this->update(
            self::baselineOf($stored),
            [FindingFactory::magnitude($symbol, 40)],
            RunScope::fromRecorded(['src']),
        );

        self::assertSame(BaselineUpdateDisposition::Refused, $result->outcomes[0]->disposition);
        self::assertSame(
            BaselineUpdateRefusalReason::WorsenedUnderSuppression,
            $result->outcomes[0]->refusalReason,
        );
        self::assertStringContainsString(
            'suppress',
            $result->outcomes[0]->refusalReason->description(),
            'the wording a user reads must say the entry still suppresses, not that the build got worse',
        );
        self::assertSame([25.0], $result->baseline->entries[0]->magnitudes, 'behaviour is unchanged: the stored numbers are kept');
    }

    /**
     * The counterpart: without `mode: suppress` the very same declined
     * comparison keeps its own name, so the new case narrows nothing else.
     */
    #[Test]
    public function itStillNamesAnOrdinaryEntrysRefusalAWorsening(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $stored = new BaselineEntry(BaselineIdentity::forFinding(FindingFactory::magnitude($symbol, 25)), [25], 1);

        $result = $this->update(
            self::baselineOf($stored),
            [FindingFactory::magnitude($symbol, 40)],
            RunScope::fromRecorded(['src']),
        );

        self::assertSame(BaselineUpdateRefusalReason::Worsened, $result->outcomes[0]->refusalReason);
    }

    /**
     * The occurrence shape reaches the same decision through a different
     * branch of {@see BaselineUpdater}, so it is pinned separately.
     */
    #[Test]
    public function itNamesASuppressedOccurrenceEntrysRefusalTheSameWay(): void
    {
        $symbol = SymbolPath::forFile(RelativePath::fromString('src/Legacy.php'));
        $identity = new BaselineIdentity($symbol->toCanonical(), self::gotoChannel());
        $stored = new BaselineEntry($identity, null, 1, BaselineEntryMode::Suppress);

        $result = $this->update(
            self::baselineOf($stored),
            [FindingFactory::occurrence($symbol), FindingFactory::occurrence($symbol)],
            RunScope::fromRecorded(['src']),
        );

        self::assertSame(
            BaselineUpdateRefusalReason::WorsenedUnderSuppression,
            $result->outcomes[0]->refusalReason,
        );
        self::assertSame(1, $result->baseline->entries[0]->count);
    }

    #[Test]
    public function itRefusesAnEntryOnAChannelNoRuleDeclares(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $finding = FindingFactory::magnitude($symbol, 5, 'nobody.declares', 'this.channel');
        $stored = new BaselineEntry(BaselineIdentity::forFinding($finding), [5], 1);

        $result = (new BaselineUpdater(new StubChannelDeclarationRegistry(), new FixedClock()))
            ->update(self::baselineOf($stored), [$finding], StubRuleCoverage::completeFor(self::baselineOf($stored)), []);

        self::assertSame(BaselineUpdateDisposition::Refused, $result->outcomes[0]->disposition);
        self::assertSame(BaselineUpdateRefusalReason::UndeclaredChannel, $result->outcomes[0]->refusalReason);
        self::assertSame([5.0], $result->baseline->entries[0]->magnitudes);
    }

    /**
     * A `Baseline` assembled in memory can hold an entry whose own shape
     * disagrees with what the channel currently declares — the loader would
     * refuse such a line, but a lifecycle command building a `Baseline`
     * directly bypasses the loader entirely (mirrors
     * {@see \Qualimetrix\Analysis\Policy\Baseline\Ceiling\BaselineCeilingStage}'s identical
     * reachability note).
     */
    #[Test]
    public function itRefusesAnEntryWhoseShapeDisagreesWithItsChannel(): void
    {
        $symbol = SymbolPath::forFile(RelativePath::fromString('src/Legacy.php'));
        // "code-smell.goto" is declared `occurrence` by the stub registry,
        // but this entry stores magnitudes — a shape mismatch that can only
        // arise from a Baseline assembled directly, not through the loader.
        $identity = new BaselineIdentity($symbol->toCanonical(), self::gotoChannel());
        $stored = new BaselineEntry($identity, [1.0], 1);

        $current = FindingFactory::occurrence($symbol);

        $result = $this->update(self::baselineOf($stored), [$current], RunScope::fromRecorded(['src']));

        self::assertSame(BaselineUpdateDisposition::Refused, $result->outcomes[0]->disposition);
        self::assertSame(BaselineUpdateRefusalReason::ShapeMismatch, $result->outcomes[0]->refusalReason);
    }

    #[Test]
    public function itRefusesAMagnitudeEntryWhoseMeasuredGroupReportsNoFiniteNumber(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $stored = new BaselineEntry(BaselineIdentity::forFinding(FindingFactory::magnitude($symbol, 15)), [15], 1);

        $noNumber = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'no magnitude reported',
            severity: Severity::Warning,
        );

        $result = $this->update(self::baselineOf($stored), [$noNumber], RunScope::fromRecorded(['src']));

        self::assertSame(BaselineUpdateDisposition::NotCompared, $result->outcomes[0]->disposition);
        self::assertSame('magnitude-unavailable', $result->outcomes[0]->reasonCode);
        self::assertSame([15.0], $result->baseline->entries[0]->magnitudes);
    }

    #[Test]
    public function itStampsTheResultFromTheInjectedClock(): void
    {
        $finding = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 10);
        $entry = new BaselineEntry(BaselineIdentity::forFinding($finding), [15], 1);
        $baseline = new Baseline(generated: new DateTimeImmutable('2020-01-01T00:00:00+00:00'), scope: ['src'], entries: [$entry], exclusions: self::fixtureExclusions());

        $result = $this->update($baseline, [$finding], RunScope::fromRecorded(['src']));

        self::assertSame('2026-08-05T12:00:00+03:00', $result->baseline->generated->format('c'));
    }

    /**
     * Ordinary update retains the recorded scope even when a run is wider
     * and tightens a measured entry.
     */
    #[Test]
    public function itRetainsTheRecordedScopeWhenTheRunIsWiderAndTightensAnEntry(): void
    {
        $finding = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 10);
        $entry = new BaselineEntry(BaselineIdentity::forFinding($finding), [15], 1);
        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [$entry], exclusions: self::fixtureExclusions());

        $result = $this->update($baseline, [$finding], RunScope::fromRecorded(['src', 'tests']));

        self::assertSame(['src'], $result->baseline->scope);
    }

    /**
     * **The `--force` that must not become permanent (ADR 0017).** The scope
     * guard is a command precondition a user overrides per invocation; if a
     * narrower run also overwrote the recorded `scope`, that one override
     * would silently become a standing rule — every later narrow run would
     * then cover the file's own (now narrow) claim and the guard would never
     * fire again.
     */
    #[Test]
    public function itKeepsTheRecordedScopeWhenTheRunDoesNotCoverIt(): void
    {
        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src', 'tests'], entries: [], exclusions: self::fixtureExclusions());

        $result = $this->update($baseline, [], RunScope::fromRecorded(['src/Legacy']));

        self::assertSame(['src', 'tests'], $result->baseline->scope);
    }

    #[Test]
    public function itCarriesTheSourceContentHashForward(): void
    {
        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [], sourceContentHash: 'abc123', exclusions: self::fixtureExclusions());

        $result = $this->update($baseline, [], RunScope::fromRecorded(['src']));

        self::assertSame('abc123', $result->baseline->sourceContentHash);
    }

    #[Test]
    public function itOnlyAddsNewIdentitiesOfExplicitChannels(): void
    {
        $old = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 30);
        $entry = new BaselineEntry(BaselineIdentity::forFinding($old), [40], 1, BaselineEntryMode::Suppress);
        $new = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'added'), 20);
        $held = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'held'), 35);
        $inert = InertBaselineEntry::forIdentity(BaselineIdentity::forFinding($held), InertEntryReason::Malformed, 'invalid count', ['channel' => 'complexity.ccn', 'count' => 0]);
        $baseline = new Baseline(new DateTimeImmutable('2000-01-01'), ['src'], [$entry], self::fixtureExclusions(), [$inert], 'held-hash');
        $other = FindingFactory::magnitude(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')), 100, 'duplication.clone', 'duplication.clone');

        $occurrence = FindingFactory::occurrence(SymbolPath::forMethod('App', 'Foo', 'gotoMethod'));
        $result = $this->updater()->acceptNew($baseline, [$old, $held, $other, $new, $occurrence], [new FindingChannel('complexity.ccn'), new FindingChannel('code-smell.goto')], StubRuleCoverage::completeFor($baseline), StubRuleCoverage::everyRuleRan());

        self::assertTrue($result->changed);
        self::assertCount(3, $result->baseline->entries);
        self::assertNull($result->baseline->entries[2]->magnitudes);
        self::assertSame(1, $result->baseline->entries[2]->count);
        self::assertSame($entry, $result->baseline->entries[0]);
        self::assertSame(BaselineIdentity::forFinding($new)->key(), $result->baseline->entries[1]->identity->key());
        self::assertSame([$inert], $result->baseline->inertEntries);
        self::assertSame($baseline->scope, $result->baseline->scope);
        self::assertSame($baseline->exclusions, $result->baseline->exclusions);
        self::assertSame('held-hash', $result->baseline->sourceContentHash);
        self::assertNotEquals($baseline->generated, $result->baseline->generated);
        self::assertSame(['existing-entry', 'inert-holds-identity', null, null], array_map(static fn($outcome) => $outcome->reasonCode, $result->outcomes));
        self::assertSame([], $result->channelNotes);
    }

    #[Test]
    public function itSkipsNewGroupsWithoutComparableCompleteEvidence(): void
    {
        $baseline = new Baseline(new DateTimeImmutable(), ['src'], [], self::fixtureExclusions());
        $channel = new FindingChannel('duplication.clone');
        $finding = FindingFactory::magnitude(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')), 20, 'duplication.clone', $channel->code);
        $narrow = StubRuleCoverage::completeFor($baseline, ['src/Other.php'], scope: RunScope::fromRecorded(['src/Foo.php']));
        $result = $this->updater()->acceptNew($baseline, [$finding], [$channel], $narrow, StubRuleCoverage::everyRuleRan());
        self::assertFalse($result->changed);
        self::assertSame('outside-coverage', $result->outcomes[0]->reasonCode);

        foreach ([\NAN, \INF, -\INF] as $value) {
            $invalid = FindingFactory::magnitude(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')), $value, 'duplication.clone', $channel->code);
            $result = $this->updater()->acceptNew($baseline, [$invalid], [$channel], StubRuleCoverage::completeFor($baseline), StubRuleCoverage::everyRuleRan());
            self::assertFalse($result->changed);
            self::assertSame('magnitude-unavailable', $result->outcomes[0]->reasonCode);
        }
        $missing = new Finding(location: $finding->location, subject: $finding->subject, symbolPath: $finding->symbolPath, ruleName: $finding->ruleName, code: $finding->code, message: $finding->message, severity: $finding->severity);
        $result = $this->updater()->acceptNew($baseline, [$missing], [$channel], StubRuleCoverage::completeFor($baseline), StubRuleCoverage::everyRuleRan());
        self::assertFalse($result->changed);
        self::assertSame('magnitude-unavailable', $result->outcomes[0]->reasonCode);
        $unknown = $this->coverageWithExclusions($baseline, ['exact:src/Foo.php'], unknown: true);
        $result = $this->updater()->acceptNew($baseline, [$finding], [$channel], $unknown, StubRuleCoverage::everyRuleRan());
        self::assertFalse($result->changed);
        self::assertSame('metadata-unknown', $result->outcomes[0]->reasonCode);
    }

    #[Test]
    public function itRerecordsOnlyExclusionAffectedGroupsWithTheirModes(): void
    {
        $affected = FindingFactory::magnitude(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')), 120, 'duplication.clone', 'duplication.clone');
        $entry = new BaselineEntry(BaselineIdentity::forFinding($affected), [40], 1, BaselineEntryMode::Suppress);
        $unaffected = FindingFactory::magnitude(SymbolPath::forMethod('App', 'New', 'bar'), 20);
        $other = new BaselineEntry(BaselineIdentity::forFinding($unaffected), [30], 1);
        $baseline = new Baseline(new DateTimeImmutable(), ['src'], [$entry, $other], self::fixtureExclusions());
        $coverage = $this->coverageWithExclusions($baseline, ['exact:src/Excluded.php']);

        $result = $this->updater()->recordExclusions($baseline, [$affected, $unaffected], $coverage, []);

        self::assertTrue($result->changed);
        self::assertNull($result->writeRefusal);
        self::assertSame([120.0], $result->baseline->entries[0]->magnitudes);
        self::assertSame(BaselineEntryMode::Suppress, $result->baseline->entries[0]->mode);
        self::assertSame([20.0], $result->baseline->entries[1]->magnitudes);
        self::assertSame(BaselineUpdateDisposition::ReRecorded, $result->outcomes[0]->disposition);
        self::assertNotNull($result->outcomes[0]->previousLevel);
        self::assertNotNull($result->outcomes[0]->currentLevel);
        self::assertSame('40', $result->outcomes[0]->previousLevel->describe());
        self::assertSame('120', $result->outcomes[0]->currentLevel->describe());
        self::assertSame($coverage->exclusions, $result->baseline->exclusions);
    }

    #[Test]
    public function itRefusesTheWholeExclusionRecordWhenItsProofIsUnavailable(): void
    {
        $finding = FindingFactory::magnitude(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')), 100, 'duplication.clone', 'duplication.clone');
        $entry = new BaselineEntry(BaselineIdentity::forFinding($finding), [40], 1);
        $baseline = self::baselineOf($entry);
        $coverage = $this->coverageWithExclusions($baseline, ['exact:src/Excluded.php']);
        foreach ([[], [FindingFactory::magnitude(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')), \INF, 'duplication.clone', 'duplication.clone')]] as $findings) {
            $result = $this->updater()->recordExclusions($baseline, $findings, $coverage, []);
            self::assertSame($baseline, $result->baseline);
            self::assertFalse($result->changed);
            self::assertSame(BaselineUpdateRefusalReason::RequiredGroupUnavailable, $result->writeRefusal);
        }
        $result = $this->updater()->recordExclusions($baseline, [$finding], $this->coverageWithExclusions($baseline, ['exact:src/Excluded.php'], unknown: true), []);
        self::assertSame($baseline, $result->baseline);
        self::assertSame(BaselineUpdateRefusalReason::ComparisonMetadataUnknown, $result->writeRefusal);
        $result = $this->updater()->recordExclusions($baseline, [$finding], $this->coverageWithExclusions($baseline, ['exact:src/Excluded.php'], includeGenerated: true), []);
        self::assertSame($baseline, $result->baseline);
        self::assertSame(BaselineUpdateRefusalReason::ComparisonMetadataUnknown, $result->writeRefusal);
        $partial = new \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage([RelativePath::fromString('src/Foo.php')], [], [new \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure(RelativePath::fromString('src/Broken.php'), \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind::Parse, 'broken fixture')]);
        $result = $this->updater()->recordExclusions($baseline, [$finding], $this->coverageWithExclusions($baseline, ['exact:src/Excluded.php'], analysis: $partial), []);
        self::assertSame($baseline, $result->baseline);
        self::assertFalse($result->changed);
        self::assertNotNull($result->writeRefusal);
        $result = $this->updater()->recordExclusions($baseline, [$finding], $coverage, [$entry->identity->key() => \Qualimetrix\Analysis\Policy\Baseline\RunCoverageGap::NotMeasured]);
        self::assertFalse($result->changed);
        self::assertNotNull($result->writeRefusal);
    }

    /** @param list<string> $patterns */
    private function coverageWithExclusions(Baseline $baseline, array $patterns, bool $unknown = false, bool $includeGenerated = false, ?\Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage $analysis = null): \Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage
    {
        $current = StubRuleCoverage::completeFor($baseline, ['src/Excluded.php'], analysis: $analysis);
        $tree = new class ($unknown) implements \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface {
            public function __construct(private bool $unknown) {}

            public function snapshot(\Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse $universe): \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot
            {
                return new \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot([RelativePath::fromString('src/Foo.php'), RelativePath::fromString('src/Excluded.php')], [], !$this->unknown);
            }

            public function hasFile(AbsolutePath $root, RelativePath $file): \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence
            {
                return \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Present;
            }

            public function hasDirectory(AbsolutePath $directory): \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence
            {
                return \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Present;
            }
        };
        return new \Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage($current->scope, $current->analysis, new \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions($patterns, $includeGenerated ? \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Include : \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Exclude), $current->universe, $current->psr4Roots, $tree, $current->subjectCoverage);
    }

    /** @param list<\Qualimetrix\Analysis\Finding\Contract\Finding> $measured */
    private function update(Baseline $baseline, array $measured, RunScope $scope): BaselineUpdateResult
    {
        return $this->updater()->update(
            $baseline,
            $measured,
            StubRuleCoverage::completeFor($baseline, scope: $scope),
            [],
        );
    }

    private function updater(): BaselineUpdater
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('code-smell.goto', ChannelDeclaration::occurrence(SymbolLevel::Callable, SymbolLevel::File));

        return new BaselineUpdater($declarations, new FixedClock());
    }

    private static function baselineOf(BaselineEntry $entry): Baseline
    {
        return new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [$entry], exclusions: self::fixtureExclusions());
    }

    private static function duplicationChannel(): FindingChannel
    {
        return new FindingChannel('duplication.clone');
    }

    private static function gotoChannel(): FindingChannel
    {
        return new FindingChannel('code-smell.goto');
    }

    private static function fixtureExclusions(): \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions
    {
        return new \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions(
            [],
            \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Exclude,
        );
    }
}
