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
    public function itReportsEveryCopyInALargeBucketAsOneBlock(): void
    {
        $finder = new DuplicateBlockFinder();

        // 150 copies: comparing them pairwise would evaluate 11175 pairs
        // and keep a block per pair; one group keeps one block.
        $positions = [];
        for ($file = 0; $file < 150; $file++) {
            $positions[] = PackedPosition::pack($file, 0);
        }

        $blocks = $finder->find($this->request([0x2a => $positions], 150));

        self::assertCount(1, $blocks);
        self::assertSame(150, $blocks[0]->occurrences());
    }

    #[Test]
    public function itCountsARepeatedPositionOnce(): void
    {
        $finder = new DuplicateBlockFinder();

        $positions = [];
        for ($i = 0; $i < 500; $i++) {
            $positions[] = PackedPosition::pack(0, 0);
            $positions[] = PackedPosition::pack(1, 0);
        }

        $blocks = $finder->find($this->request([0x2a => $positions]));

        self::assertCount(1, $blocks);
        self::assertSame(2, $blocks[0]->occurrences());
    }

    #[Test]
    public function itLeavesAWindowThatContinuesAnEarlierMatchToThatMatch(): void
    {
        $finder = new DuplicateBlockFinder();

        // Offset 1 of both files is preceded by the same `foo`, so only the
        // offset-0 bucket reports the block.
        $blocks = $finder->find($this->request([
            0x2a => [PackedPosition::pack(0, 0), PackedPosition::pack(1, 0)],
            0x2b => [PackedPosition::pack(0, 1), PackedPosition::pack(1, 1)],
        ], minTokens: 1));

        self::assertCount(1, $blocks);
        self::assertSame(2, $blocks[0]->tokens);
    }

    #[Test]
    public function itReportsTwoCopiesAsOneBlock(): void
    {
        $finder = new DuplicateBlockFinder();

        $blocks = $finder->find($this->request([
            0x2a => [PackedPosition::pack(0, 0), PackedPosition::pack(1, 0)],
        ]));

        self::assertCount(1, $blocks, 'Two copies must yield their duplicate block');
    }

    /**
     * Builds a search request over files whose token streams all match, so
     * any group of offset-0 positions yields a real duplicate block.
     *
     * @param array<int, list<int>> $hashIndex
     */
    private function request(array $hashIndex, int $fileCount = 2, int $minTokens = 2): DuplicateSearchRequest
    {
        $matching = [
            new NormalizedToken(\T_STRING, 'foo', 1),
            new NormalizedToken(\T_STRING, 'bar', 1),
        ];

        return new DuplicateSearchRequest(
            hashIndex: $hashIndex,
            retokenized: new RetokenizedFiles(array_fill(0, $fileCount, $matching), []),
            filePaths: array_map(static fn(int $file): string => "f{$file}.php", range(0, $fileCount - 1)),
            minTokens: $minTokens,
            minLines: 1,
        );
    }
}
