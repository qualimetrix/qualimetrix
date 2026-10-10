<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\ValueReach;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\EntryComparability;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\IncomparabilityReason;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\Region;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\SubjectRegion;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(EntryComparability::class)]
final class EntryComparabilityTest extends TestCase
{
    #[Test]
    public function itComparesEqualWholePathsAndDefinitionsWithoutRequestingATreeSnapshot(): void
    {
        $baseline = self::baseline([], ['src']);
        $tree = self::tree([], false);
        $coverage = self::coverage([], ['src'], $baseline->exclusions, $tree);

        self::assertTrue(EntryComparability::judge(Region::whole(), $baseline, $coverage)->canCompare());
        self::assertSame(0, $tree->snapshots);
    }

    #[Test]
    public function itRefusesWholeAbsenceOutsideTheSnapshotDenominator(): void
    {
        $file = RelativePath::fromString('src/A.php');
        $baseline = self::baseline([], ['src', 'extras']);
        $tree = self::tree([$file], true);
        $coverage = self::coverage([$file], ['src'], $baseline->exclusions, $tree);

        self::assertSame(
            IncomparabilityReason::MetadataUnknown,
            EntryComparability::judge(Region::whole(), $baseline, $coverage)->reason,
        );

        $entry = new BaselineEntry(new BaselineIdentity('file:src/A.php', new FindingChannel('duplication.clone')), [10], 1);
        $recorded = new Baseline(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $baseline->scope, [$entry], $baseline->exclusions);
        $outcome = (new BaselineCeilingStage($recorded, StubChannelDeclarationRegistry::withDefaults(), $coverage, []))->judgeAll([]);
        self::assertSame([], $outcome->staleEntries);
        self::assertSame([$entry], $outcome->notComparedEntries);
        self::assertSame('metadata-unknown', $outcome->reasonFor($entry->identity));
        self::assertSame(1, $tree->snapshots);
    }

    #[Test]
    public function itRefusesNamespaceAbsenceOutsideTheSnapshotDenominator(): void
    {
        $file = RelativePath::fromString('src/A.php');
        $baseline = self::baseline([], ['src', 'extras']);
        $coverage = self::coverage([$file], ['src'], $baseline->exclusions, self::tree([$file], true));

        foreach ([['extras'], ['src', 'extras']] as $roots) {
            $region = Region::namespace(array_map(RelativePath::fromString(...), $roots));
            self::assertSame(
                IncomparabilityReason::MetadataUnknown,
                EntryComparability::judge($region, $baseline, $coverage)->reason,
            );
        }
    }

    #[Test]
    public function itComparesAnExactAnalyzedPresentFileWithoutComposerOrSnapshot(): void
    {
        $baseline = self::baseline([], ['src']);
        $neighbor = RelativePath::fromString('src/Neighbor.php');

        foreach ([
            'plain' => 'src/Plain.php',
            'percent' => 'src/One%Two.php',
            'hash' => 'src/One#Two.php',
            'at' => 'src/One@Two.php',
            'literal percent FF' => 'src/One%FF.php',
            'raw FF byte' => "src/One\xFF.php",
            'terminal hash ordinal spelling' => 'src/One.php#2',
            'literal percent 23' => 'src/One%23.php',
        ] as $spelling => $rawPath) {
            $file = RelativePath::fromString($rawPath);
            foreach ([
                'file' => MetricSubject::aggregate(SymbolPath::forFile($file)),
                'declaration first' => MetricSubject::declaration(DeclarationPath::of(
                    SymbolPath::forClass('App', 'Example'),
                    $file,
                    DeclarationOrdinal::fromRank(0),
                )),
                'declaration ordinal 2' => MetricSubject::declaration(DeclarationPath::of(
                    SymbolPath::forClass('App', 'Example'),
                    $file,
                    DeclarationOrdinal::fromRank(2),
                )),
            ] as $form => $subject) {
                $identity = new BaselineIdentity($subject->toCanonical(), new FindingChannel('code-smell.goto'));
                $region = SubjectRegion::forIdentity($identity, ValueReach::Members, []);
                $tree = self::tree([$file, $neighbor], false);
                $coverage = self::coverage([$file], ['src'], $baseline->exclusions, $tree);
                $case = $spelling . ' / ' . $form;

                self::assertSame('file', $region->kind, $case);
                self::assertTrue($region->contains($file), $case);
                self::assertSame($rawPath, $region->file?->value(), $case);
                self::assertFalse($region->contains($neighbor), $case);
                self::assertTrue(EntryComparability::judge($region, $baseline, $coverage)->canCompare(), $case);
                self::assertSame(0, $tree->snapshots, $case);
            }
        }
    }

    #[Test]
    public function itRefusesUnknownFilePresenceInsteadOfTreatingItAsAbsence(): void
    {
        $file = RelativePath::fromString('src/Legacy.php');
        $baseline = self::baseline([], ['src']);
        $tree = self::tree([], false, ProjectEntryPresence::Unknown);
        $coverage = self::coverage([], ['src'], $baseline->exclusions, $tree);

        self::assertSame(
            IncomparabilityReason::MetadataUnknown,
            EntryComparability::judge(Region::file($file), $baseline, $coverage)->reason,
        );
        self::assertSame(0, $tree->snapshots);

        $entry = new BaselineEntry(new BaselineIdentity('file:src/Legacy.php', new FindingChannel('code-smell.goto')), null, 1);
        $recorded = new Baseline(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), ['src'], [$entry], $baseline->exclusions);
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('code-smell.goto', ChannelDeclaration::occurrence(SymbolLevel::File));
        $outcome = (new BaselineCeilingStage($recorded, $declarations, $coverage, []))->judgeAll([]);
        self::assertSame([], $outcome->staleEntries);
        self::assertSame([$entry], $outcome->notComparedEntries);
        self::assertSame('metadata-unknown', $outcome->reasonFor($entry->identity));
    }

    #[Test]
    public function itDistinguishesAChangedExclusionOutsideTheRegionFromUnknownTreeMetadata(): void
    {
        $baseline = self::baseline([], ['src']);
        $changed = new RecordedExclusions(['subtree:src/Other'], GeneratedFilePolicy::Exclude);
        $region = Region::file(RelativePath::fromString('src/Legacy.php'));
        $present = self::tree([RelativePath::fromString('src/Legacy.php')], true);
        $coverage = self::coverage([RelativePath::fromString('src/Legacy.php')], ['src'], $changed, $present);
        self::assertTrue(EntryComparability::judge($region, $baseline, $coverage)->canCompare());

        $domain = RelativePath::fromString('src/Domain/Entry.php');
        $outside = RelativePath::fromString('src/Other/Excluded.php');
        $broadTree = self::tree([$domain, $outside], true);
        $broadCoverage = self::coverage([$domain], ['src'], $changed, $broadTree);
        self::assertTrue(EntryComparability::judge(Region::namespace([RelativePath::fromString('src/Domain')]), $baseline, $broadCoverage)->canCompare());
        self::assertSame(1, $broadTree->snapshots);

        $unknown = self::tree([], false);
        $wide = self::coverage([], ['src'], $changed, $unknown);
        self::assertSame(
            IncomparabilityReason::MetadataUnknown,
            EntryComparability::judge(Region::whole(), $baseline, $wide)->reason,
        );
        self::assertSame(1, $unknown->snapshots);

        $generatedChanged = new RecordedExclusions([], GeneratedFilePolicy::Include);
        $generatedTree = self::tree([RelativePath::fromString('src/Legacy.php')], true);
        $generatedRun = self::coverage([RelativePath::fromString('src/Legacy.php')], ['src'], $generatedChanged, $generatedTree);
        self::assertTrue(EntryComparability::judge($region, $baseline, $generatedRun)->canCompare());
        self::assertSame(IncomparabilityReason::MetadataUnknown, EntryComparability::judge(Region::whole(), $baseline, $generatedRun)->reason);
        self::assertSame(1, $generatedTree->snapshots);
    }

    #[Test]
    public function itMarksAKnownAbsentFileStaleOnFullAndNarrowRunsIncludingMovesAndCaseRenames(): void
    {
        $entry = new BaselineEntry(new BaselineIdentity('file:src/Legacy.php', new FindingChannel('code-smell.goto')), null, 1);
        $baseline = new Baseline(
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ['src'],
            [$entry],
            new RecordedExclusions([], GeneratedFilePolicy::Exclude),
        );

        foreach ([
            'removed full' => [[], ['src']],
            'removed narrow' => [[], ['src/Other']],
            'moved' => [[RelativePath::fromString('src/Moved.php')], ['src']],
            'moved narrow' => [[RelativePath::fromString('src/Moved.php')], ['src/Other']],
            'case renamed' => [[RelativePath::fromString('src/legacy.php')], ['src']],
            'case renamed narrow' => [[RelativePath::fromString('src/legacy.php')], ['src/Other']],
        ] as $case => [$present, $paths]) {
            $tree = self::tree($present, true);
            $coverage = self::coverage($present, $paths, $baseline->exclusions, $tree);
            $declarations = StubChannelDeclarationRegistry::withDefaults();
            $declarations->declare('code-smell.goto', ChannelDeclaration::occurrence(SymbolLevel::File));
            $outcome = (new BaselineCeilingStage($baseline, $declarations, $coverage, []))->judgeAll([]);
            self::assertSame([$entry], $outcome->staleEntries, $case);
            self::assertSame('stale', $outcome->statusFor($entry->identity), $case);
            self::assertSame(0, $tree->snapshots, $case);
        }
    }

    #[Test]
    public function itDoesNotCallAMissingFileStaleWhenTheRecordedRootIsMissing(): void
    {
        $entry = new BaselineEntry(new BaselineIdentity('file:src/Legacy.php', new FindingChannel('code-smell.goto')), null, 1);
        $baseline = new Baseline(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), ['src'], [$entry], new RecordedExclusions([], GeneratedFilePolicy::Exclude));
        $coverage = self::coverage([], ['src/Other'], $baseline->exclusions, self::tree([], true, directoryPresence: ProjectEntryPresence::Absent));
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('code-smell.goto', ChannelDeclaration::occurrence(SymbolLevel::File));

        $outcome = (new BaselineCeilingStage($baseline, $declarations, $coverage, []))->judgeAll([]);

        self::assertSame([], $outcome->staleEntries);
        self::assertSame([$entry], $outcome->outsideCoverageEntries);
    }

    #[Test]
    public function itDoesNotUseAnUnrelatedExistingAbsoluteRootToProveFileAbsence(): void
    {
        $recordedRoot = (string) realpath(sys_get_temp_dir());
        $baseline = self::baseline([], [$recordedRoot]);
        $coverage = self::coverage([], ['src/Other'], $baseline->exclusions, self::tree([], true));

        self::assertSame(
            IncomparabilityReason::OutsideCoverage,
            EntryComparability::judge(Region::file(RelativePath::fromString('src/Gone.php')), $baseline, $coverage)->reason,
        );
    }

    #[Test]
    public function itCanCallADeletedFileSubjectStaleEvenWhenItsProducerReadsTheRun(): void
    {
        $entry = new BaselineEntry(new BaselineIdentity('file:src/Legacy.php', new FindingChannel('duplication.clone')), [10], 1);
        $baseline = new Baseline(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), ['src'], [$entry], new RecordedExclusions([], GeneratedFilePolicy::Exclude));
        $coverage = self::coverage([], ['src/Other'], $baseline->exclusions, self::tree([], true));

        $outcome = (new BaselineCeilingStage($baseline, StubChannelDeclarationRegistry::withDefaults(), $coverage, []))->judgeAll([]);

        self::assertSame([$entry], $outcome->staleEntries);
    }

    /** @param list<string> $patterns
     * @param list<string> $scope
     */
    private static function baseline(array $patterns, array $scope): Baseline
    {
        return new Baseline(
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $scope,
            [],
            new RecordedExclusions($patterns, GeneratedFilePolicy::Exclude),
        );
    }

    /** @param list<RelativePath> $analyzed
     * @param list<string> $scope
     */
    private static function coverage(array $analyzed, array $scope, RecordedExclusions $exclusions, ProjectTreeQueryInterface $tree): RunCoverage
    {
        $root = AbsolutePath::fromString('/fixture');

        return new RunCoverage(
            RunScope::fromRecorded($scope),
            new AnalysisCoverage($analyzed, [], []),
            $exclusions,
            new ProjectScopeUniverse($root, true, [['target' => 'src', 'path' => $root->joinRelative(RelativePath::fromString('src'))]], [], [], false, []),
            [],
            $tree,
            \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), $analyzed, []),
        );
    }

    /**
     * @param list<RelativePath> $files
     *
     * @return ProjectTreeQueryInterface&object{snapshots: int}
     */
    private static function tree(array $files, bool $complete, ?ProjectEntryPresence $override = null, ProjectEntryPresence $directoryPresence = ProjectEntryPresence::Present): ProjectTreeQueryInterface
    {
        return new class ($files, $complete, $override, $directoryPresence) implements ProjectTreeQueryInterface {
            public int $snapshots = 0;

            /** @param list<RelativePath> $files */
            public function __construct(private array $files, private bool $complete, private ?ProjectEntryPresence $override, private ProjectEntryPresence $directoryPresence) {}

            public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence
            {
                return $this->directoryPresence;
            }

            public function snapshot(ProjectScopeUniverse $universe): ProjectTreeSnapshot
            {
                ++$this->snapshots;

                return new ProjectTreeSnapshot($this->files, [], $this->complete);
            }

            public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence
            {
                if ($this->override !== null) {
                    return $this->override;
                }
                foreach ($this->files as $present) {
                    if ($present->equals($file)) {
                        return ProjectEntryPresence::Present;
                    }
                }

                return ProjectEntryPresence::Absent;
            }
        };
    }
}
