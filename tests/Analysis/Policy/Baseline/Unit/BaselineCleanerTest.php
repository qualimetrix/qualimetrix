<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleanupReason;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEdge;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\EntrySelector;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverageGap;
use Qualimetrix\Analysis\Policy\Baseline\RunRuleCoverage;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FindingFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubRuleCoverage;

/**
 * ADR 0017's rules for `baseline:cleanup`: enumeration is read-only,
 * and removal touches exactly the selectors it is given.
 */
#[CoversClass(BaselineCleaner::class)]
#[CoversClass(RunRuleCoverage::class)]
#[CoversClass(RunCoverageGap::class)]
final class BaselineCleanerTest extends TestCase
{
    #[Test]
    public function itOffersAFileEntryWhoseOwnPopulationWasNewlyExcluded(): void
    {
        $finding = FindingFactory::magnitude(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')), 40, 'duplication.clone', 'duplication.clone');
        $entry = new BaselineEntry(BaselineIdentity::forFinding($finding), [40], 1);
        $baseline = self::baselineOf($entry);
        $baseCoverage = StubRuleCoverage::completeFor($baseline);
        $tree = new class implements ProjectTreeQueryInterface {
            public function snapshot(\Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse $universe): ProjectTreeSnapshot
            {
                return new ProjectTreeSnapshot([RelativePath::fromString('src/Foo.php')], [], true);
            }

            public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence
            {
                return ProjectEntryPresence::Present;
            }

            public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence
            {
                return ProjectEntryPresence::Present;
            }
        };
        $coverage = new RunCoverage(
            $baseCoverage->scope,
            $baseCoverage->analysis,
            new RecordedExclusions(['exact:src/Foo.php'], GeneratedFilePolicy::Exclude),
            $baseCoverage->universe,
            $baseCoverage->psr4Roots,
            $tree,
            $baseCoverage->subjectCoverage,
        );

        $candidates = $this->cleaner()->candidates($baseline, [], StubChannelDeclarationRegistry::withDefaults(), [], $coverage);

        self::assertCount(1, $candidates);
        self::assertSame(BaselineCleanupReason::ExclusionsRemovedPopulation, $candidates[0]->reason);
        self::assertSame($entry->selector()->value, $candidates[0]->selector->value);
    }

    #[Test]
    public function itListsAStaleEntryWhoseIdentityDidNotAppearInTheRun(): void
    {
        $repaired = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $entry = new BaselineEntry(BaselineIdentity::forFinding($repaired), [15], 1);

        $candidates = $this->candidates(
            self::baselineOf($entry),
            [],
            StubChannelDeclarationRegistry::withDefaults(),
            [],
        );

        self::assertCount(1, $candidates);
        self::assertSame(BaselineCleanupReason::Stale, $candidates[0]->reason);
        self::assertSame($entry->selector()->value, $candidates[0]->selector->value);
    }

    /**
     * An entry whose rule did not run is absent because nothing looked, and
     * is listed under its own reason rather than as stale: a selector or a
     * rule switched off in configuration, the same answer either way.
     *
     * @return iterable<string, array{RunRuleCoverage}>
     */
    public static function provideSkippedProducers(): iterable
    {
        yield 'left out by a selector' => [StubRuleCoverage::withSkipped(notSelected: ['complexity.ccn'])];
        yield 'disabled at every level by configuration' => [StubRuleCoverage::withSkipped(disabledEverywhere: ['complexity.ccn'])];
    }

    #[Test]
    #[DataProvider('provideSkippedProducers')]
    public function itListsAnEntryWhoseRuleDidNotRunUnderItsOwnReason(RunRuleCoverage $coverage): void
    {
        $unmeasured = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $entry = new BaselineEntry(BaselineIdentity::forFinding($unmeasured), [15], 1);

        $candidates = $this->candidates(
            self::baselineOf($entry),
            [],
            StubChannelDeclarationRegistry::withDefaults(),
            $coverage->classify([$entry->identity]),
        );

        self::assertCount(1, $candidates);
        self::assertSame(BaselineCleanupReason::ProducerDidNotRun, $candidates[0]->reason);
    }

    /**
     * The legitimate neighbour: another rule being left out does not change
     * the reason of an entry whose own rule ran and reported nothing — a
     * loosened threshold looks exactly like this, and "nothing reported" is
     * true of it.
     */
    #[Test]
    public function itStillListsAnEntryWhoseRuleRanAsStale(): void
    {
        $repaired = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $entry = new BaselineEntry(BaselineIdentity::forFinding($repaired), [15], 1);

        $candidates = $this->candidates(
            self::baselineOf($entry),
            [],
            StubChannelDeclarationRegistry::withDefaults(),
            StubRuleCoverage::withSkipped(notSelected: ['code-smell.goto'])->classify([$entry->identity]),
        );

        self::assertCount(1, $candidates);
        self::assertSame(BaselineCleanupReason::Stale, $candidates[0]->reason);
    }

    #[Test]
    public function itNamesTheRetiredProjectCopyLevelAndKeepsAPresentFileCopy(): void
    {
        $channel = new FindingChannel('duplication.clone');
        $project = new BaselineEntry(new BaselineIdentity(SymbolPath::forProject()->toCanonical(), $channel), [40], 1);
        $fileFinding = FindingFactory::magnitude(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')), 40, $channel->code, $channel->code);
        $file = new BaselineEntry(BaselineIdentity::forFinding($fileFinding), [40], 1);
        $coverage = StubRuleCoverage::everyRuleRan()->classify([$project->identity, $file->identity]);

        self::assertSame([], $coverage);

        $candidates = $this->candidates(
            self::baselineOf($project, $file),
            [$fileFinding],
            StubChannelDeclarationRegistry::withDefaults(),
            $coverage,
        );

        self::assertCount(1, $candidates);
        self::assertSame($project->selector()->value, $candidates[0]->selector->value);
        self::assertSame(BaselineCleanupReason::LevelNotDeclared, $candidates[0]->reason);
    }

    #[Test]
    public function itClassifiesADisabledFileCopyAsNotMeasured(): void
    {
        $channel = new FindingChannel('duplication.clone');
        $file = new BaselineEntry(new BaselineIdentity(SymbolPath::forFile(RelativePath::fromString('src/Foo.php'))->toCanonical(), $channel), [40], 1);
        $coverage = StubRuleCoverage::withSkipped(notSelected: [$channel->code])->classify([$file->identity]);

        self::assertSame([$file->identity->key() => RunCoverageGap::NotMeasured], $coverage);

        $candidates = $this->candidates(
            self::baselineOf($file),
            [],
            StubChannelDeclarationRegistry::withDefaults(),
            $coverage,
        );

        self::assertCount(1, $candidates);
        self::assertSame(BaselineCleanupReason::ProducerDidNotRun, $candidates[0]->reason);
    }

    #[Test]
    public function itLeavesUndeclaredLevelsToTheLoaderAndIgnoresAnUnknownProducer(): void
    {
        $channel = new FindingChannel('complexity.ccn');
        $callable = new BaselineIdentity('callable:App\\Foo::bar', $channel);
        $class = new BaselineIdentity('class:App\\Foo', $channel);
        $project = new BaselineIdentity('project:', $channel);
        $unknown = new BaselineIdentity('project:', new FindingChannel('legacy.unknown'));

        self::assertSame(
            [],
            StubRuleCoverage::everyRuleRan()->classify([$callable, $class, $project, $unknown]),
        );
    }

    #[Test]
    public function itListsAnEntryWhoseChannelIsNoLongerDeclared(): void
    {
        $finding = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15, 'nobody.declares', 'this.channel');
        $entry = new BaselineEntry(BaselineIdentity::forFinding($finding), [15], 1);

        $candidates = $this->candidates(
            self::baselineOf($entry),
            [$finding],
            new StubChannelDeclarationRegistry(),
            [$entry->identity->key() => RunCoverageGap::NotMeasured],
        );

        self::assertCount(1, $candidates);
        self::assertSame(BaselineCleanupReason::ChannelNotDeclared, $candidates[0]->reason);
    }

    /**
     * A channel no rule declares can never produce a measured finding, so an
     * entry on it is always stale too — the more specific, more permanent
     * reason is reported, not both.
     */
    #[Test]
    public function itPrefersChannelNotDeclaredOverStaleWhenBothApply(): void
    {
        $finding = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15, 'nobody.declares', 'this.channel');
        $entry = new BaselineEntry(BaselineIdentity::forFinding($finding), [15], 1);

        $candidates = $this->candidates(
            self::baselineOf($entry),
            [],
            new StubChannelDeclarationRegistry(),
            [],
        );

        self::assertCount(1, $candidates);
        self::assertSame(BaselineCleanupReason::ChannelNotDeclared, $candidates[0]->reason);
    }

    #[Test]
    public function itDoesNotListAnEntryThatIsStillMeasuredAndDeclared(): void
    {
        $finding = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $entry = new BaselineEntry(BaselineIdentity::forFinding($finding), [15], 1);

        $candidates = $this->candidates(
            self::baselineOf($entry),
            [$finding],
            StubChannelDeclarationRegistry::withDefaults(),
            [],
        );

        self::assertSame([], $candidates);
    }

    #[Test]
    public function itListsAnInertEntryWithItsOwnReason(): void
    {
        $inert = self::inertEntry('file:src/Legacy.php', InertEntryReason::UnrecognizedMode);

        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [], inertEntries: [$inert], exclusions: self::fixtureExclusions());

        $candidates = $this->candidates($baseline, [], StubChannelDeclarationRegistry::withDefaults(), []);

        self::assertCount(1, $candidates);
        self::assertSame(BaselineCleanupReason::Inert, $candidates[0]->reason);
        self::assertSame(InertEntryReason::UnrecognizedMode, $candidates[0]->inertReason);
        self::assertSame($inert->selector->value, $candidates[0]->selector->value);
    }

    #[Test]
    public function itNeverRemovesAnythingWhenOnlyListingCandidates(): void
    {
        $repaired = FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $entry = new BaselineEntry(BaselineIdentity::forFinding($repaired), [15], 1);
        $baseline = self::baselineOf($entry);

        $this->candidates($baseline, [], StubChannelDeclarationRegistry::withDefaults(), []);

        self::assertSame([$entry], $baseline->entries, 'candidates() must not mutate the baseline it was given');
    }

    #[Test]
    public function itRemovesExactlyTheNamedEntryAndLeavesItsNeighbours(): void
    {
        $kept = new BaselineEntry(new BaselineIdentity('callable:App\Foo::kept', self::gotoChannel()), null, 1);
        $removed = new BaselineEntry(new BaselineIdentity('callable:App\Foo::gone', self::gotoChannel()), null, 1);
        $selector = $removed->selector();

        $baseline = self::baselineOf($kept, $removed);

        $result = $this->cleaner()->remove($baseline, [$selector]);

        self::assertSame([$selector], $result->removed);
        self::assertSame([], $result->notFound);
        self::assertSame([], $result->ambiguous);
        self::assertSame([$kept], $result->baseline->entries);
    }

    /**
     * The selector addresses the complete identity, edge included — two
     * forbidden edges out of one class on one channel agree on everything
     * else, so removing one must not remove both.
     */
    #[Test]
    public function itRemovesOneOfTwoEntriesDifferingOnlyByEdge(): void
    {
        $channel = new FindingChannel('architecture.layer-violation');
        $toConnection = new BaselineEntry(
            new BaselineIdentity('class:App\Web\Controller', $channel, null, new BaselineEdge('class:App\Db\Connection', DependencyType::New_)),
            null,
            1,
        );
        $toStatement = new BaselineEntry(
            new BaselineIdentity('class:App\Web\Controller', $channel, null, new BaselineEdge('class:App\Db\Statement', DependencyType::New_)),
            null,
            1,
        );

        $baseline = self::baselineOf($toConnection, $toStatement);

        $result = $this->cleaner()->remove($baseline, [$toStatement->selector()]);

        self::assertSame([$toConnection], $result->baseline->entries);
    }

    #[Test]
    public function itRemovesAnInertEntryAddressedByItsSelector(): void
    {
        $kept = self::inertEntry('file:kept.php', InertEntryReason::Malformed);
        $removed = self::inertEntry('file:gone.php', InertEntryReason::Malformed);

        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [], inertEntries: [$kept, $removed], exclusions: self::fixtureExclusions());

        $result = $this->cleaner()->remove($baseline, [$removed->selector]);

        self::assertSame([$removed->selector], $result->removed);
        self::assertSame([], $result->notFound);
        self::assertSame([], $result->ambiguous);
        self::assertSame([$kept], $result->baseline->inertEntries);
    }

    #[Test]
    public function itReportsASelectorThatNamesNothing(): void
    {
        $entry = new BaselineEntry(new BaselineIdentity('callable:App\Foo::bar', self::gotoChannel()), null, 1);
        $baseline = self::baselineOf($entry);

        $unknown = EntrySelector::fromString('000000000000');
        $result = $this->cleaner()->remove($baseline, [$unknown]);

        self::assertSame([$unknown], $result->notFound);
        self::assertSame([], $result->removed);
        self::assertSame([$entry], $result->baseline->entries);
    }

    /**
     * The digest is not a proof of uniqueness ({@see EntrySelector}); when a
     * selector names more than one entry, `remove()` reports the ambiguity
     * and picks neither, rather than guessing.
     */
    #[Test]
    public function itReportsAnAmbiguousSelectorAndRemovesNeitherEntry(): void
    {
        $shared = EntrySelector::fromString('aaaaaaaaaaaa');

        $first = self::inertEntry('file:one.php', InertEntryReason::Malformed, $shared);
        $second = self::inertEntry('file:two.php', InertEntryReason::Malformed, $shared);

        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [], inertEntries: [$first, $second], exclusions: self::fixtureExclusions());

        $result = $this->cleaner()->remove($baseline, [$shared]);

        self::assertSame([$shared], $result->ambiguous);
        self::assertSame([], $result->removed);
        self::assertSame([$first, $second], $result->baseline->inertEntries);
    }

    #[Test]
    public function itRemovesEveryInertContenderOfOneDuplicateIdentity(): void
    {
        $identity = new BaselineIdentity('file:src/Legacy.php', self::gotoChannel());
        $first = InertBaselineEntry::forIdentity($identity, InertEntryReason::DuplicateIdentity, 'first contender', ['count' => 1]);
        $second = InertBaselineEntry::forIdentity($identity, InertEntryReason::DuplicateIdentity, 'second contender', ['count' => 2]);
        $baseline = new Baseline(
            generated: new DateTimeImmutable(),
            scope: ['src'],
            entries: [],
            inertEntries: [$first, $second],
            exclusions: self::fixtureExclusions(),
        );

        $selector = $identity->selector();
        $result = $this->cleaner()->remove($baseline, [$selector]);

        self::assertSame([$selector], $result->removed);
        self::assertSame([], $result->ambiguous);
        self::assertSame([], $result->baseline->inertEntries);
    }

    #[Test]
    public function itKeepsAValidAndInertEntryWithTheSameSelectorAmbiguous(): void
    {
        $identity = new BaselineIdentity('file:src/Legacy.php', self::gotoChannel());
        $valid = new BaselineEntry($identity, null, 1);
        $inert = InertBaselineEntry::forIdentity($identity, InertEntryReason::DuplicateIdentity, 'duplicate', ['count' => 2]);
        $baseline = new Baseline(
            generated: new DateTimeImmutable(),
            scope: ['src'],
            entries: [$valid],
            inertEntries: [$inert],
            exclusions: self::fixtureExclusions(),
        );
        $selector = $identity->selector();

        $result = $this->cleaner()->remove($baseline, [$selector]);

        self::assertSame([$selector], $result->ambiguous);
        self::assertSame([], $result->removed);
        self::assertSame([$valid], $result->baseline->entries);
        self::assertSame([$inert], $result->baseline->inertEntries);
    }

    #[Test]
    public function itKeepsDifferentInertReasonsWithTheSameSelectorAmbiguous(): void
    {
        $identity = new BaselineIdentity('file:src/Legacy.php', self::gotoChannel());
        $duplicate = InertBaselineEntry::forIdentity($identity, InertEntryReason::DuplicateIdentity, 'duplicate', ['count' => 1]);
        $shape = InertBaselineEntry::forIdentity($identity, InertEntryReason::ShapeMismatch, 'wrong shape', ['count' => 2]);
        $baseline = new Baseline(
            generated: new DateTimeImmutable(),
            scope: ['src'],
            entries: [],
            inertEntries: [$duplicate, $shape],
            exclusions: self::fixtureExclusions(),
        );
        $selector = $identity->selector();

        $result = $this->cleaner()->remove($baseline, [$selector]);

        self::assertSame([$selector], $result->ambiguous);
        self::assertSame([], $result->removed);
        self::assertSame([$duplicate, $shape], $result->baseline->inertEntries);
    }

    #[Test]
    public function itClassifiesEachSelectorValueOnlyOnceInFirstOccurrenceOrder(): void
    {
        $removedEntry = new BaselineEntry(new BaselineIdentity('callable:App\Foo::gone', self::gotoChannel()), null, 1);
        $removed = $removedEntry->selector();
        $notFound = EntrySelector::fromString('000000000000');
        $ambiguous = EntrySelector::fromString('aaaaaaaaaaaa');
        $first = self::inertEntry('file:one.php', InertEntryReason::Malformed, $ambiguous);
        $second = self::inertEntry('file:two.php', InertEntryReason::Malformed, $ambiguous);
        $baseline = new Baseline(
            generated: new DateTimeImmutable(),
            scope: ['src'],
            entries: [$removedEntry],
            inertEntries: [$first, $second],
            exclusions: self::fixtureExclusions(),
        );

        $result = $this->cleaner()->remove($baseline, [
            $notFound,
            $removed,
            $ambiguous,
            $removed,
            $notFound,
            $ambiguous,
        ]);

        self::assertSame([$removed], $result->removed);
        self::assertSame([$notFound], $result->notFound);
        self::assertSame([$ambiguous], $result->ambiguous);
        self::assertSame([], $result->baseline->entries);
        self::assertSame([$first, $second], $result->baseline->inertEntries);
    }

    #[Test]
    public function itLeavesTheBaselineEntriesUnchangedWhenGivenNoSelectors(): void
    {
        $entry = new BaselineEntry(new BaselineIdentity('callable:App\Foo::bar', self::gotoChannel()), null, 1);
        $baseline = self::baselineOf($entry);

        $result = $this->cleaner()->remove($baseline, []);

        self::assertSame([$entry], $result->baseline->entries);
        self::assertSame([], $result->removed);
    }

    #[Test]
    public function itStampsTheWrittenBaselineFromTheInjectedClock(): void
    {
        $entry = new BaselineEntry(new BaselineIdentity('callable:App\Foo::bar', self::gotoChannel()), null, 1);
        $baseline = self::baselineOf($entry);

        $result = $this->cleaner()->remove($baseline, [$entry->selector()]);

        self::assertSame('2026-08-05T12:00:00+03:00', $result->baseline->generated->format('c'));
    }

    #[Test]
    public function itCarriesTheSourceContentHashForward(): void
    {
        $entry = new BaselineEntry(new BaselineIdentity('callable:App\Foo::bar', self::gotoChannel()), null, 1);
        $baseline = new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [$entry], sourceContentHash: 'abc123', exclusions: self::fixtureExclusions());

        $result = $this->cleaner()->remove($baseline, []);

        self::assertSame('abc123', $result->baseline->sourceContentHash);
    }

    /**
     * @param list<\Qualimetrix\Analysis\Finding\Contract\Finding> $measured
     * @param array<string, RunCoverageGap> $gaps
     *
     * @return list<\Qualimetrix\Analysis\Policy\Baseline\BaselineCleanupCandidate>
     */
    private function candidates(
        Baseline $baseline,
        array $measured,
        \Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface $declarations,
        array $gaps,
    ): array {
        return $this->cleaner()->candidates(
            $baseline,
            $measured,
            $declarations,
            $gaps,
            StubRuleCoverage::completeFor($baseline),
        );
    }

    private function cleaner(): BaselineCleaner
    {
        return new BaselineCleaner(new FixedClock());
    }

    private static function baselineOf(BaselineEntry ...$entries): Baseline
    {
        return new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: array_values($entries), exclusions: self::fixtureExclusions());
    }

    private static function gotoChannel(): FindingChannel
    {
        return new FindingChannel('code-smell.goto');
    }

    private static function inertEntry(string $symbolKey, InertEntryReason $reason, ?EntrySelector $selector = null): InertBaselineEntry
    {
        return new InertBaselineEntry(
            subjectKey: $symbolKey,
            channelKey: null,
            identity: null,
            selector: $selector ?? EntrySelector::forKey($symbolKey),
            reason: $reason,
            detail: 'test fixture',
            raw: 'garbage',
        );
    }

    private static function fixtureExclusions(): \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions
    {
        return new \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions(
            [],
            \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Exclude,
        );
    }
}
