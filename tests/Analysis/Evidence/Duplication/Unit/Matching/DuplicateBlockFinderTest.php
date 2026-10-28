<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Matching;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateBlockFinder;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateSearchRequest;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenNormalizer;
use Qualimetrix\Analysis\Evidence\Duplication\Token\RetokenizedFiles;
use Qualimetrix\Tests\Analysis\Evidence\Duplication\Support\SplitSameContentFixture;

#[CoversClass(DuplicateBlockFinder::class)]
final class DuplicateBlockFinderTest extends TestCase
{
    #[Test]
    public function itUnitesSplitEvidenceForTheSameNormalizedContent(): void
    {
        $request = SplitSameContentFixture::request();
        $first = \array_slice($request->retokenized->streams[0]->values, SplitSameContentFixture::FIRST_OFFSET, SplitSameContentFixture::CONTENT_LENGTH);
        $second = \array_slice($request->retokenized->streams[0]->values, SplitSameContentFixture::SECOND_OFFSET, SplitSameContentFixture::CONTENT_LENGTH);
        self::assertSame($first, $second);
        $hash = hash('sha256', json_encode(['tokenCount' => \count($first), 'tokens' => $first], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        $blocks = (new DuplicateBlockFinder())->find($request);
        $sameContent = array_values(array_filter($blocks, static fn($block): bool => $block->contentHash === $hash));

        self::assertCount(1, $sameContent);
        self::assertSame(
            ['src/F04.php:20', 'src/F04.php:86', 'src/F05.php:72', 'src/F05.php:98', 'src/F10.php:27', 'src/F10.php:60'],
            array_map(static fn($location): string => $location->file->value() . ':' . $location->startLine, $sameContent[0]->locations),
        );
    }

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

    #[Test]
    public function itKeepsDifferentMatchesWithTheSamePhysicalLineSpan(): void
    {
        $sources = ['<?php first($a); uniqueA() + second($a);', '<?php first($b); uniqueB() - second($b);'];
        $normalizer = new TokenNormalizer();
        $request = new DuplicateSearchRequest(
            hashIndex: [
                1 => [PackedPosition::pack(0, 0), PackedPosition::pack(1, 0)],
                2 => [PackedPosition::pack(0, 9), PackedPosition::pack(1, 9)],
            ],
            retokenized: new RetokenizedFiles(array_map($normalizer->normalize(...), $sources), $sources),
            filePaths: ['first.php', 'second.php'],
            minTokens: 5,
            minLines: 1,
        );

        $blocks = (new DuplicateBlockFinder())->find($request);

        self::assertCount(2, $blocks);
        self::assertNotSame($blocks[0]->contentHash, $blocks[1]->contentHash);
        self::assertSame('first($a);', $blocks[0]->locations[0]->hint);
        self::assertSame('second($a);', $blocks[1]->locations[0]->hint);
    }

    /**
     * One literal of 1000 elements is a 2000-token periodic run whose ~1000
     * window-equal positions all overlap. Re-reading every remaining member
     * at every extension step costs about a million operations here and took
     * seconds per file; the bound is linear in the run.
     */
    #[Test]
    #[DataProvider('provideLongLiteralRuns')]
    public function itExtendsALongLiteralRunInLinearWork(string $source, bool $reported): void
    {
        $request = $this->exactWindowRequest($source, minTokens: 70);
        $tokens = $request->retokenized->streams[0]->count();
        $finder = new DuplicateBlockFinder();

        $blocks = $finder->find($request);

        self::assertSame($reported, $blocks !== []);
        self::assertGreaterThanOrEqual($tokens, $finder->extensionWork(), 'Extension work must be counted');
        self::assertLessThanOrEqual(12 * $tokens, $finder->extensionWork());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideLongLiteralRuns(): iterable
    {
        $rows = implode(",\n", array_map(
            static fn(array $row): string => implode(', ', $row),
            array_chunk(range(0, 999), 10),
        ));

        yield 'data table' => ["<?php\nfinal class T\n{\n    protected array \$t = [\n{$rows}\n    ];\n}\n", false];
        yield 'executable literal' => ["<?php\nfunction t(): array\n{\n    return [\n{$rows}\n    ];\n}\n", true];
    }

    /**
     * Indexes every window of one source by its exact tokens, standing in for
     * the rolling-hash index without its collisions.
     */
    private function exactWindowRequest(string $source, int $minTokens): DuplicateSearchRequest
    {
        $stream = (new TokenNormalizer())->normalize($source);
        $windows = [];
        for ($offset = 0; $offset + $minTokens <= $stream->count(); $offset++) {
            $windows[implode("\0", \array_slice($stream->values, $offset, $minTokens))][] = PackedPosition::pack(0, $offset);
        }

        return new DuplicateSearchRequest(
            hashIndex: array_values(array_filter($windows, static fn(array $positions): bool => \count($positions) >= 2)),
            retokenized: new RetokenizedFiles([$stream], [$source]),
            filePaths: ['t.php'],
            minTokens: $minTokens,
            minLines: 5,
        );
    }

    /**
     * Builds a search request over files whose token streams all match, so
     * any group of offset-0 positions yields a real duplicate block.
     *
     * @param array<int, list<int>> $hashIndex
     */
    private function request(array $hashIndex, int $fileCount = 2, int $minTokens = 2): DuplicateSearchRequest
    {
        $matching = (new TokenNormalizer())->normalize('<?php foo bar');

        return new DuplicateSearchRequest(
            hashIndex: $hashIndex,
            retokenized: new RetokenizedFiles(array_fill(0, $fileCount, $matching), []),
            filePaths: array_map(static fn(int $file): string => "f{$file}.php", range(0, $fileCount - 1)),
            minTokens: $minTokens,
            minLines: 1,
        );
    }
}
