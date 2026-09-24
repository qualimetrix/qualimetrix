<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateMatchCandidates;

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

    /**
     * Each copy position is its own file; a match of N tokens spans lines 1..N.
     *
     * @return Closure(int, int): array{string, int, int}
     */
    private static function spans(): Closure
    {
        return static fn(int $copy, int $length): array => ["f{$copy}.php", 1, $length];
    }
}
