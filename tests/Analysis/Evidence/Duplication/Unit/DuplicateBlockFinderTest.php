<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateBlockFinder;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateSearchRequest;
use Qualimetrix\Analysis\Evidence\Duplication\NormalizedToken;
use Qualimetrix\Analysis\Evidence\Duplication\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\RetokenizedFiles;

#[CoversClass(DuplicateBlockFinder::class)]
final class DuplicateBlockFinderTest extends TestCase
{
    #[Test]
    public function itSkipsABucketWithMoreThanTheMaximumPositionCount(): void
    {
        $finder = new DuplicateBlockFinder();

        // A genuine duplicate pair buried in a ~1000-position bucket (mirrors
        // the doctrine-dbal keyword tables / this repo's builtin-class
        // registry). Without the guard the O(n²) loop still finds the one real
        // block among ~500k pair evaluations; with it the whole bucket is
        // skipped as pathological boilerplate.
        $positions = [];
        for ($i = 0; $i < 500; $i++) {
            $positions[] = PackedPosition::pack(0, 0);
            $positions[] = PackedPosition::pack(1, 0);
        }

        $blocks = $finder->find($this->request([0x2a => $positions]));

        self::assertSame([], $blocks, 'An over-large bucket must be skipped, not pair-evaluated');
    }

    #[Test]
    public function itStillEvaluatesABucketWithinThePositionLimit(): void
    {
        $finder = new DuplicateBlockFinder();

        $blocks = $finder->find($this->request([
            0x2a => [PackedPosition::pack(0, 0), PackedPosition::pack(1, 0)],
        ]));

        self::assertCount(1, $blocks, 'A bucket within the limit must still yield its duplicate block');
    }

    /**
     * Builds a search request over two files whose token streams match, so
     * any evaluated pair of (file 0, offset 0) and (file 1, offset 0) yields
     * a real duplicate block.
     *
     * @param array<int, list<int>> $hashIndex
     */
    private function request(array $hashIndex): DuplicateSearchRequest
    {
        $matching = [
            new NormalizedToken(\T_STRING, 'foo', 1),
            new NormalizedToken(\T_STRING, 'bar', 1),
        ];

        return new DuplicateSearchRequest(
            hashIndex: $hashIndex,
            retokenized: new RetokenizedFiles(
                [0 => $matching, 1 => $matching],
                [],
            ),
            filePaths: ['a.php', 'b.php'],
            minTokens: 2,
            minLines: 1,
        );
    }
}
