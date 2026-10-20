<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Matching;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateMatchCandidates;

#[CoversClass(DuplicateMatchCandidates::class)]
final class DuplicateMatchCandidatesTest extends TestCase
{
    #[Test]
    public function itDropsAMatchWhoseCopiesAllLieInsideALongerOne(): void
    {
        $candidates = new DuplicateMatchCandidates();
        $candidates->add(10, [100, 200]);
        $candidates->add(20, [100, 200]);

        self::assertSame([[20, [100, 200]]], $candidates->withoutSubsumed(self::spans()));
    }

    /**
     * Two copies agreeing for longer and a third agreeing only for the start:
     * the shorter match still has a copy no longer match covers.
     */
    #[Test]
    public function itKeepsAShorterMatchWithACopyOutsideEveryLongerOne(): void
    {
        $candidates = new DuplicateMatchCandidates();
        $candidates->add(10, [100, 200, 300]);
        $candidates->add(20, [100, 200]);

        self::assertSame([[20, [100, 200]], [10, [100, 200, 300]]], $candidates->withoutSubsumed(self::spans()));
    }

    #[Test]
    public function itJudgesMatchesOfEqualLengthInTheOrderTheyWereAdded(): void
    {
        $candidates = new DuplicateMatchCandidates();
        $candidates->add(10, [300, 400]);
        $candidates->add(10, [100, 200]);

        self::assertSame([[10, [300, 400]], [10, [100, 200]]], $candidates->withoutSubsumed(self::spans()));
    }

    #[Test]
    public function itKeepsAllSixFilePairsWhenLongerMatchesCoverDisconnectedGroups(): void
    {
        $candidates = new DuplicateMatchCandidates();
        $ab = [PackedPosition::pack(0, 0), PackedPosition::pack(1, 0)];
        $cd = [PackedPosition::pack(2, 0), PackedPosition::pack(3, 0)];
        $all = [...$ab, ...$cd];
        $candidates->add(40, $ab);
        $candidates->add(40, $cd);
        $candidates->add(30, $all);

        $kept = $candidates->withoutSubsumed(self::tokenSpans());
        self::assertSame([[40, $ab], [40, $cd], [30, $all]], $kept);
        $pairs = [];
        foreach ($kept as [, $copies]) {
            foreach ($copies as $i => $left) {
                foreach (\array_slice($copies, $i + 1) as $right) {
                    $pairs[PackedPosition::fileIndex($left) . ':' . PackedPosition::fileIndex($right)] = true;
                }
            }
        }
        self::assertSame(['0:1', '2:3', '0:2', '0:3', '1:2', '1:3'], array_keys($pairs));
    }

    #[Test]
    public function itDropsAShorterMatchCoveredByOneConnectedComponent(): void
    {
        $candidates = new DuplicateMatchCandidates();
        $ab = [PackedPosition::pack(0, 0), PackedPosition::pack(1, 0)];
        $bc = [PackedPosition::pack(1, 10), PackedPosition::pack(2, 0)];
        $candidates->add(20, $ab);
        $candidates->add(20, $bc);
        $candidates->add(10, [PackedPosition::pack(0, 10), PackedPosition::pack(1, 10), PackedPosition::pack(2, 0)]);

        self::assertSame([[20, $ab], [20, $bc]], $candidates->withoutSubsumed(self::tokenSpans()));
    }

    #[Test]
    public function itResetsConnectivityAfterAnUncoveredCopyKeepsACandidate(): void
    {
        $candidates = new DuplicateMatchCandidates();
        $ab = [PackedPosition::pack(0, 0), PackedPosition::pack(1, 0)];
        $bc = [PackedPosition::pack(1, 10), PackedPosition::pack(2, 0)];
        $bridge = [PackedPosition::pack(0, 10), PackedPosition::pack(1, 10), PackedPosition::pack(2, 0), PackedPosition::pack(3, 0)];
        $separate = [PackedPosition::pack(0, 0), PackedPosition::pack(2, 15)];
        $candidates->add(20, $ab);
        $candidates->add(20, $bc);
        $candidates->add(10, $bridge);
        $candidates->add(5, $separate);

        self::assertSame([[20, $ab], [20, $bc], [10, $bridge], [5, $separate]], $candidates->withoutSubsumed(self::tokenSpans()));
    }

    #[Test]
    public function itJudgesEqualLengthMatchesWithMoreCopiesFirst(): void
    {
        $candidates = new DuplicateMatchCandidates();
        $candidates->add(10, [100, 200]);
        $candidates->add(10, [100, 200, 300]);

        self::assertSame([[10, [100, 200, 300]]], $candidates->withoutSubsumed(self::spans()));
    }

    #[Test]
    public function itPreservesCopyOrderAcrossStorageSegmentsAndTheIntegerRange(): void
    {
        self::assertSame([], (new DuplicateMatchCandidates())->withoutSubsumed(self::spans()));
        foreach ([[0, 0, 4294967295, PackedPosition::pack(1, 7), \PHP_INT_MAX], [7, 3, 7], [\PHP_INT_MIN, \PHP_INT_MAX, \PHP_INT_MIN]] as $copies) {
            $candidates = new DuplicateMatchCandidates();
            $candidates->add(\PHP_INT_MAX, $copies);
            self::assertSame([[\PHP_INT_MAX, $copies]], $candidates->withoutSubsumed(self::spans()));
        }
        $ordered = array_fill(0, 32766, PackedPosition::pack(0, 128));
        $ordered[] = PackedPosition::pack(0, 256);
        $candidates = new DuplicateMatchCandidates();
        $candidates->add(7, $ordered);
        self::assertSame([[7, $ordered]], $candidates->withoutSubsumed(self::spans()), 'A two-byte offset delta crosses byte65536');

        $fixed = array_fill(0, 8191, \PHP_INT_MIN);
        $fixed[] = \PHP_INT_MAX;
        $candidates = new DuplicateMatchCandidates();
        $candidates->add(8, $fixed);
        self::assertSame([[8, $fixed]], $candidates->withoutSubsumed(self::spans()), 'The last q64 word crosses byte65536');

        $candidates = new DuplicateMatchCandidates();
        $fillers = [];
        for ($id = 0; $id < 1023; $id++) {
            $copies = [$id * 4, $id * 4 + 1];
            $candidates->add(1, $copies);
            $fillers[] = [1, $copies];
        }
        $at1023 = [\PHP_INT_MIN, -1];
        $at1024 = [\PHP_INT_MAX - 2, \PHP_INT_MAX - 1, \PHP_INT_MAX];
        $candidates->add(\PHP_INT_MAX, $at1023);
        $candidates->add(500, $at1024);
        self::assertSame([[\PHP_INT_MAX, $at1023], [500, $at1024], ...$fillers], $candidates->withoutSubsumed(self::spans()), 'Adjacent metadata records retain their own length, count and payload offset');

        $candidates = new DuplicateMatchCandidates();
        $candidates->add(10, [\PHP_INT_MIN, -1]);
        $candidates->add(10, [5000, 5001, 5002]);
        $candidates->add(10, [6000, 6001, 6002]);
        self::assertSame([[10, [5000, 5001, 5002]], [10, [6000, 6001, 6002]], [10, [\PHP_INT_MIN, -1]]], $candidates->withoutSubsumed(self::spans()), 'Copy count precedes payload size and insertion id breaks ties');
    }

    #[Test]
    public function itKeepsRetainedCandidatesWithinTheAllocationBudget(): void
    {
        $warm = new DuplicateMatchCandidates();
        $warm->add(10, [100, 200, 300]);
        unset($warm);
        $before = memory_get_usage();
        $candidates = new DuplicateMatchCandidates();
        for ($candidate = 0; $candidate < 20000; $candidate++) {
            $candidates->add(10, [100, 200, 300]);
        }
        $retained = memory_get_usage() - $before;
        self::assertLessThanOrEqual(1024 * 1024, $retained, 'Retained candidates must not allocate a PHP copy list or string per candidate');
        self::assertSame([[10, [100, 200, 300]]], $candidates->withoutSubsumed(self::spans()));
    }

    /** @return Closure(int, int): array{int, int, int} */
    private static function tokenSpans(): Closure
    {
        return static fn(int $copy, int $length): array => [
            PackedPosition::fileIndex($copy),
            PackedPosition::offset($copy),
            PackedPosition::offset($copy) + $length,
        ];
    }

    /**
     * Each copy position is its own file.
     *
     * @return Closure(int, int): array{int, int, int}
     */
    private static function spans(): Closure
    {
        return static fn(int $copy, int $length): array => [$copy, 0, $length];
    }
}
