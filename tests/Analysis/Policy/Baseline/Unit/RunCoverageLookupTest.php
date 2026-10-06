<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts;
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

#[CoversClass(RunCoverage::class)]
final class RunCoverageLookupTest extends TestCase
{
    #[Test]
    public function itUsesOneCompleteInventoryForManyDistinctFiles(): void
    {
        $files = array_map(
            static fn(int $index): RelativePath => RelativePath::fromString('src/Item' . $index . '.php'),
            range(1, 12),
        );
        $tree = new class ($files) implements ProjectTreeQueryInterface {
            public int $snapshots = 0;
            public int $exact = 0;

            /** @param list<RelativePath> $files */
            public function __construct(private array $files) {}

            public function snapshot(ProjectScopeUniverse $universe): ProjectTreeSnapshot
            {
                ++$this->snapshots;

                return new ProjectTreeSnapshot($this->files, [], true);
            }

            public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence
            {
                ++$this->exact;

                return ProjectEntryPresence::Present;
            }

            public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence
            {
                return ProjectEntryPresence::Present;
            }
        };
        $coverage = self::coverage($files, $tree);

        foreach ($files as $file) {
            self::assertTrue($coverage->analyzed($file));
            self::assertSame(ProjectEntryPresence::Present, $coverage->hasFile($file));
        }
        self::assertFalse($coverage->analyzed(RelativePath::fromString('src/Absent.php')));
        self::assertSame(1, $tree->snapshots, 'The complete tree must be indexed once for a multi-entry run.');
        self::assertLessThanOrEqual(2, $tree->exact, 'Distinct file lookups must not each issue a tree query.');
    }

    #[Test]
    public function itMemoizesAnExactUnknownAnswerWithoutClaimingAbsence(): void
    {
        $file = RelativePath::fromString('src/Unreadable.php');
        $tree = new class implements ProjectTreeQueryInterface {
            public int $exact = 0;

            public function snapshot(ProjectScopeUniverse $universe): ProjectTreeSnapshot
            {
                return new ProjectTreeSnapshot([], [], false);
            }

            public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence
            {
                ++$this->exact;

                return ProjectEntryPresence::Unknown;
            }

            public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence
            {
                return ProjectEntryPresence::Unknown;
            }
        };
        $coverage = self::coverage([], $tree);

        self::assertSame(ProjectEntryPresence::Unknown, $coverage->hasFile($file));
        self::assertSame(ProjectEntryPresence::Unknown, $coverage->hasFile($file));
        self::assertSame(1, $tree->exact);
        self::assertFalse($coverage->analyzed($file));
    }

    #[Test]
    public function itStillQueriesAnOutOfDenominatorFileExactly(): void
    {
        $tree = new class implements ProjectTreeQueryInterface {
            public int $exact = 0;

            public function snapshot(ProjectScopeUniverse $universe): ProjectTreeSnapshot
            {
                return new ProjectTreeSnapshot([], [], true);
            }

            public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence
            {
                ++$this->exact;

                return ProjectEntryPresence::Unknown;
            }

            public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence
            {
                return ProjectEntryPresence::Unknown;
            }
        };
        $coverage = self::coverage([], $tree);

        self::assertSame(ProjectEntryPresence::Unknown, $coverage->hasFile(RelativePath::fromString('vendor/External.php')));
        self::assertSame(1, $tree->exact);
    }

    #[Test]
    public function itQueriesAnOmittedBuiltInFloorExactlyAfterUsingTheInventory(): void
    {
        $tree = new class implements ProjectTreeQueryInterface {
            public int $exact = 0;

            public function snapshot(ProjectScopeUniverse $universe): ProjectTreeSnapshot
            {
                return new ProjectTreeSnapshot([RelativePath::fromString('src/Foo.php')], [], true);
            }

            public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence
            {
                ++$this->exact;

                return $file->value() === 'src/vendor/Hidden.php'
                    ? ProjectEntryPresence::Unknown
                    : ProjectEntryPresence::Present;
            }

            public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence
            {
                return ProjectEntryPresence::Present;
            }
        };
        $coverage = self::coverage([], $tree);
        $coverage->hasFile(RelativePath::fromString('src/Foo.php'));
        $coverage->hasFile(RelativePath::fromString('src/Other.php'));

        self::assertSame(ProjectEntryPresence::Unknown, $coverage->hasFile(RelativePath::fromString('src/vendor/Hidden.php')));
        self::assertSame(2, $tree->exact);
    }

    /**
     * @param list<RelativePath> $files
     */
    private static function coverage(array $files, ProjectTreeQueryInterface $tree): RunCoverage
    {
        $root = AbsolutePath::fromString('/tmp/qmx-coverage-lookup');

        return new RunCoverage(
            RunScope::fromRecorded(['src']),
            new AnalysisCoverage($files, [], []),
            new RecordedExclusions([], GeneratedFilePolicy::Exclude),
            new ProjectScopeUniverse(
                $root,
                false,
                [['target' => 'src', 'path' => $root->joinRelative(RelativePath::fromString('src'))]],
                [],
                [],
                true,
                [],
            ),
            [],
            $tree,
            SubjectCoverageFacts::fromMeasured(new ProjectScopeJudgement(), $files, []),
        );
    }
}
