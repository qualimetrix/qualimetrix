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
