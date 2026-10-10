<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\GateError;
use QmxFindingGate\RankingOrder;

final class RankingOrderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itPreservesDuplicateOccurrencesAndSelectsTheEarliestIndexPairs(): void
    {
        $result = RankingOrder::measure(['X', 'X', 'Y', 'X'], ['X', 'Y', 'X', 'X']);
        self::assertSame([
            ['referenceIndex' => 0, 'candidateIndex' => 0],
            ['referenceIndex' => 1, 'candidateIndex' => 2],
            ['referenceIndex' => 3, 'candidateIndex' => 3],
        ], $result['lcs']);
        self::assertSame([['label' => 'Y', 'referencePosition' => 3, 'candidatePosition' => 2, 'occurrence' => 1]], $result['moved']);
        self::assertSame([], RankingOrder::measure(['X', 'X'], ['X', 'X'])['moved']);
    }

    #[Test]
    public function itKeepsComplementOccurrencesWhoseOrdinalsCoincide(): void
    {
        $result = RankingOrder::measure(['A', 'B', 'C'], ['C', 'B', 'A']);
        self::assertSame([
            ['label' => 'B', 'referencePosition' => 2, 'candidatePosition' => 2, 'occurrence' => 1],
            ['label' => 'C', 'referencePosition' => 3, 'candidatePosition' => 1, 'occurrence' => 1],
        ], $result['moved']);
    }

    #[Test]
    public function itAgreesWithExhaustiveSubsequenceEnumerationIncludingEveryTie(): void
    {
        foreach (self::words() as $reference) {
            foreach (self::words() as $candidate) {
                $a = $reference;
                $b = $candidate;
                sort($a);
                sort($b);
                if ($a !== $b) {
                    continue;
                }
                $choices = [];
                self::enumerate($reference, $candidate, 0, 0, [], $choices);
                usort($choices, static fn(array $left, array $right): int => \count($right) !== \count($left) ? \count($right) <=> \count($left) : $left <=> $right);
                self::assertSame($choices[0], RankingOrder::measure($reference, $candidate)['lcs']);
            }
        }
    }

    #[Test]
    public function itRefusesUnequalOccurrencePopulations(): void
    {
        $this->expectException(GateError::class);
        RankingOrder::measure(['X', 'X'], ['X']);
    }

    /** @return list<list<string>> */
    private static function words(): array
    {
        $words = [[]];
        for ($size = 1; $size <= 4; ++$size) {
            for ($bits = 0; $bits < 2 ** $size; ++$bits) {
                $word = [];
                for ($index = 0; $index < $size; ++$index) {
                    $word[] = ($bits & (1 << $index)) === 0 ? 'A' : 'B';
                }
                $words[] = $word;
            }
        }
        return $words;
    }

    /** @param list<string> $reference
     * @param list<string> $candidate
     * @param list<array{referenceIndex:int,candidateIndex:int}> $chosen
     * @param list<list<array{referenceIndex:int,candidateIndex:int}>> $choices
     */
    private static function enumerate(array $reference, array $candidate, int $from, int $to, array $chosen, array &$choices): void
    {
        $choices[] = $chosen;
        for ($i = $from; $i < \count($reference); ++$i) {
            for ($j = $to; $j < \count($candidate); ++$j) {
                if ($reference[$i] === $candidate[$j]) {
                    self::enumerate($reference, $candidate, $i + 1, $j + 1, [...$chosen, ['referenceIndex' => $i, 'candidateIndex' => $j]], $choices);
                }
            }
        }
    }
}
