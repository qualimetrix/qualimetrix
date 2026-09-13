<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\PromiseEffect;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\Cell;
use Qualimetrix\PromiseEffect\Floor;
use Qualimetrix\PromiseEffect\FloorRow;
use Qualimetrix\PromiseEffect\LedgerError;

/**
 * `Floor` checks each claimed defect against both the frozen and live result.
 * The `pending:` disposition distinguishes a cure that lands after the frozen
 * snapshot and prevents absent, narrowed, or stale evidence from proving a
 * repair.
 *
 * `scripts/promise-effect.php` is not required here — it runs on include and
 * exits — so `Floor` and the two files it depends on for `Cell` and
 * `LedgerError` are required directly, the way `DirectiveAuditGateTest`
 * reaches `scripts/directive-audit/*.php`.
 */
final class FloorTest extends TestCase
{
    /** @var list<string> */
    private array $scratchRoots = [];

    public static function setUpBeforeClass(): void
    {
        $scripts = \dirname(__DIR__, 3) . '/scripts';

        require_once $scripts . '/promise-effect/Ledger.php';
        require_once $scripts . '/promise-effect/Classifier.php';
        require_once $scripts . '/promise-effect/Stand.php';
        require_once $scripts . '/promise-effect/Floor.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchRoots as $root) {
            self::removeTree($root);
        }

        $this->scratchRoots = [];
    }

    // --- the `pending:` sentinel ---------------------------------------

    #[Test]
    public function itPassesAPendingRowStillDefectiveOnTheFrozenHalfAndCleanOnTheLiveGrid(): void
    {
        $floor = Floor::load($this->floorRoot("r1\tany-defect\tsrc\tpending: awaiting verification\t\n"));

        [$frozenMisses] = $floor->cureMisses([self::cell('r1', true)], frozenHalf: true);
        [$liveMisses] = $floor->cureMisses([self::cell('r1', false)], frozenHalf: false);

        self::assertSame([], $frozenMisses, 'still a defect on the frozen half must not miss');
        self::assertSame([], $liveMisses, 'no longer a defect on the live grid must not miss');
    }

    #[Test]
    public function itMissesAPendingRowStillDefectiveOnTheLiveGridAndNamesTheGrid(): void
    {
        [$misses] = Floor::load($this->floorRoot("r1\tany-defect\tsrc\tpending: awaiting verification\t\n"))
            ->cureMisses([self::cell('r1', true)], frozenHalf: false);

        self::assertCount(1, $misses);
        // `declared pending (` is the sentinel's own phrasing — a plain
        // `cure` miss reads `declared cured by …` instead, so this also
        // proves the two dispositions are not folded into one message.
        self::assertStringStartsWith('r1: declared pending (', $misses[0]);
        self::assertStringContainsString('the grid still calls it', $misses[0]);
    }

    #[Test]
    public function itMissesAPendingRowAlreadyCleanOnTheFrozenHalfAndSaysTheSnapshotPostdatesTheCure(): void
    {
        [$misses] = Floor::load($this->floorRoot("r1\tany-defect\tsrc\tpending: awaiting verification\t\n"))
            ->cureMisses([self::cell('r1', false)], frozenHalf: true);

        self::assertCount(1, $misses);
        self::assertStringContainsString('snapshot postdates the cure', $misses[0]);
    }

    // --- absence from the grid is a miss under every disposition --------

    #[Test]
    public function itMissesEveryDispositionWhenTheRowIsAbsentFromTheGrid(): void
    {
        $floor = Floor::load($this->floorRoot(
            "empty\tany-defect\tsrc\t\t\n"
            . "commit\tany-defect\tsrc\tabc1234 (the fix)\t\n"
            . "pending\tany-defect\tsrc\tpending: the fix\t\n"
            . "withdrawn\tany-defect\tsrc\t\treason: gone\n",
        ));

        // The grid carries none of the four rows at all.
        [$misses] = $floor->cureMisses([], frozenHalf: false);

        self::assertCount(4, $misses, 'an absent row must miss under every disposition: ' . implode('; ', $misses));

        foreach (['empty', 'commit', 'pending', 'withdrawn'] as $row) {
            self::assertNotEmpty(
                array_filter($misses, static fn(string $miss): bool => str_starts_with($miss, $row . ':')),
                $row . ' should have missed while absent from the grid: ' . implode('; ', $misses),
            );
        }
    }

    // --- bit, not word: a cure/pending row's "no longer a defect" check ---

    #[Test]
    public function itMissesAPendingRowThatSwappedOneDefectForAnotherOnTheLiveGrid(): void
    {
        // Floor row declares LOST_SIBLING; the live grid now reads a
        // DIFFERENT defective verdict entirely (FRANKENSTEIN), still with
        // `defect=true`. Before this test's fix landed, `held` compared
        // verdict NAMES, so the mismatched name made `held` false and the
        // row read as cured — the exact silent-success class the floor
        // exists to catch, now on its own `pending`/`cure` half.
        [$misses] = Floor::load($this->floorRoot("r1\tLOST_SIBLING\tsrc\tpending: awaiting verification\t\n"))
            ->cureMisses([self::cellVerdict('r1', 'FRANKENSTEIN', true)], frozenHalf: false);

        self::assertCount(1, $misses, 'a pending row that swapped one defect for another must miss, not cure silently');
        self::assertStringContainsString('the grid still calls it', $misses[0]);
    }

    #[Test]
    public function itMissesAPlainCureRowThatSwappedOneDefectForAnotherOnTheLiveGrid(): void
    {
        [$misses] = Floor::load($this->floorRoot("r1\tLOST_SIBLING\tsrc\tabc1234 (the fix)\t\n"))
            ->cureMisses([self::cellVerdict('r1', 'FRANKENSTEIN', true)], frozenHalf: false);

        self::assertCount(1, $misses, 'a cured row that swapped one defect for another must miss, not cure silently');
    }

    #[Test]
    public function itAcceptsAPendingRowThatChangedNameAndIsNoLongerADefect(): void
    {
        // The bit is what matters: a different name AND `defect=false` is a
        // legitimate cure, not a miss.
        [$misses, , , , $pending] = Floor::load($this->floorRoot("r1\tLOST_SIBLING\tsrc\tpending: awaiting verification\t\n"))
            ->cureMisses([self::cellVerdict('r1', 'COMPOSED_AS_PROMISED', false)], frozenHalf: false);

        self::assertSame([], $misses);
        self::assertCount(1, $pending);
    }

    // --- NOT OBSERVABLE is a probe regression, never a free cure ---------

    #[Test]
    public function itMissesAPlainCureRowReadAsNotObservableOnTheLiveGrid(): void
    {
        // `NOT OBSERVABLE` carries `defect = false` unconditionally, the same
        // bit a genuine repair would also produce. A `cure` claims the
        // product was fixed, not that the probe stopped being able to see
        // the row, so the two must not be read off the same bit.
        [$misses] = Floor::load($this->floorRoot("r1\tany-defect\tsrc\tabc1234 (the fix)\t\n"))
            ->cureMisses([self::cellVerdict('r1', 'NOT OBSERVABLE', false)], frozenHalf: false);

        self::assertCount(1, $misses, 'a cure read as NOT OBSERVABLE must miss, not cure silently');
        self::assertStringContainsString('can no longer observe it', $misses[0]);
    }

    #[Test]
    public function itMissesAPendingRowReadAsNotObservableOnTheLiveGrid(): void
    {
        [$misses] = Floor::load($this->floorRoot("r1\tany-defect\tsrc\tpending: awaiting verification\t\n"))
            ->cureMisses([self::cellVerdict('r1', 'NOT OBSERVABLE', false)], frozenHalf: false);

        self::assertCount(1, $misses, 'a pending row read as NOT OBSERVABLE must miss, not cure silently');
        self::assertStringContainsString('can no longer observe it', $misses[0]);
    }

    // --- unchanged dispositions ------------------------------------------

    #[Test]
    public function itLeavesACommitValuedCureUnchangedInBothDirections(): void
    {
        $floor = Floor::load($this->floorRoot("r1\tany-defect\tsrc\tabc1234 (the fix)\t\n"));

        [$frozenStillDefect] = $floor->cureMisses([self::cell('r1', true)], frozenHalf: true);
        [$liveStillDefect] = $floor->cureMisses([self::cell('r1', true)], frozenHalf: false);
        [$frozenRepaired] = $floor->cureMisses([self::cell('r1', false)], frozenHalf: true);
        [$liveRepaired] = $floor->cureMisses([self::cell('r1', false)], frozenHalf: false);

        self::assertCount(1, $frozenStillDefect, 'a commit-valued cure must miss when still a defect, frozen half included');
        self::assertCount(1, $liveStillDefect, 'a commit-valued cure must miss when still a defect, live grid included');
        self::assertSame([], $frozenRepaired, 'a commit-valued cure reading repaired must not miss on the frozen half');
        self::assertSame([], $liveRepaired, 'a commit-valued cure reading repaired must not miss on the live grid');
    }

    #[Test]
    public function itRefusesARowDeclaredBothCuredAndWithdrawn(): void
    {
        $this->expectException(LedgerError::class);

        Floor::load($this->floorRoot("r1\tany-defect\tsrc\tabc1234 (the fix)\treason: gone\n"));
    }

    #[Test]
    public function itRefusesAPendingRowWithNoClaimAfterTheSentinel(): void
    {
        $this->expectException(LedgerError::class);

        Floor::load($this->floorRoot("r1\tany-defect\tsrc\tpending: \t\n"));
    }

    // --- pendingRows(), for the retake guard ------------------------------

    #[Test]
    public function itNamesEveryPendingRowForTheRetakeGuard(): void
    {
        $pending = Floor::load($this->floorRoot(
            "r1\tany-defect\tsrc\tpending: one\t\n"
            . "r2\tany-defect\tsrc\t\t\n"
            . "r3\tany-defect\tsrc\tabc1234 (a plain cure)\t\n"
            . "r4\tany-defect\tsrc\tpending: two\t\n",
        ))->pendingRows();

        self::assertSame(['r1', 'r4'], array_map(static fn(FloorRow $row): string => $row->row, $pending));
        self::assertSame('one', $pending[0]->cureText());
        self::assertSame('two', $pending[1]->cureText());
    }

    #[Test]
    public function itReportsNoPendingRowsWhenNoneStands(): void
    {
        self::assertSame([], Floor::load($this->floorRoot("r1\tany-defect\tsrc\tabc1234 (a plain cure)\t\n"))->pendingRows());
    }

    // --- --freeze-before refuses a narrowed --axis ------------------------

    #[Test]
    public function itRefusesFreezeBeforeNarrowedByAxis(): void
    {
        $problem = Floor::narrowedFreezeProblem(['A', 'B'], ['A', 'B', 'C', 'D', 'E']);

        self::assertNotNull($problem);
        self::assertStringContainsString('--axis', $problem);
    }

    #[Test]
    public function itAcceptsFreezeBeforeOverTheWholeCanonicalList(): void
    {
        self::assertNull(Floor::narrowedFreezeProblem(['A', 'B', 'C'], ['A', 'B', 'C']));
    }

    #[Test]
    public function itRefusesFreezeBeforeWhenADuplicateAxisStandsInForAMissingOne(): void
    {
        // Same COUNT as canonical (5 against 5), but the set is not the
        // same: `D` is named twice and `E` is not named at all. A guard
        // comparing counts alone would wave this through and let `E` never
        // reach the snapshot.
        $problem = Floor::narrowedFreezeProblem(['A', 'B', 'C', 'D', 'D'], ['A', 'B', 'C', 'D', 'E']);

        self::assertNotNull($problem);
        self::assertStringContainsString('--axis', $problem);
    }

    // --- --before refuses a snapshot whose axes do not cover the canonical list

    #[Test]
    public function itRefusesABeforeSnapshotWhoseAxesDoNotCoverTheCanonicalList(): void
    {
        $problem = Floor::incompleteSnapshotProblem(['A', 'B'], ['A', 'B', 'C', 'D', 'E']);

        self::assertNotNull($problem);
        self::assertStringContainsString('C,D,E', $problem);
    }

    #[Test]
    public function itAcceptsABeforeSnapshotWhoseAxesCoverTheCanonicalList(): void
    {
        self::assertNull(Floor::incompleteSnapshotProblem(['A', 'B', 'C', 'D', 'E'], ['A', 'B', 'C', 'D', 'E']));
    }

    private static function cell(string $key, bool $defect): Cell
    {
        return new Cell('A', $key, 'probe', 'point', 'A_VERDICT', 'decided', 'DECIDED', $defect);
    }

    private static function cellVerdict(string $key, string $verdict, bool $defect): Cell
    {
        return new Cell('C', $key, 'probe', 'point', $verdict, 'decided', 'DECIDED', $defect);
    }

    /** Writes a synthetic `floor.tsv` under a fresh scratch root and returns the root `Floor::load()` expects. */
    private function floorRoot(string $body): string
    {
        $root = sys_get_temp_dir() . '/qmx-floor-test-' . bin2hex(random_bytes(6));
        mkdir($root . '/promise-effect', 0o775, true);
        file_put_contents(
            $root . '/promise-effect/floor.tsv',
            "row\tverdict\tsource\tcure\twithdrawn\n" . $body,
        );

        $this->scratchRoots[] = $root;

        return $root;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }

        rmdir($dir);
    }
}
