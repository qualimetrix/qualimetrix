<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Matching;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\AnchoredPrefixLengths;
use Qualimetrix\Analysis\Evidence\Duplication\Token\TokenStream;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(AnchoredPrefixLengths::class)]
final class AnchoredPrefixLengthsTest extends TestCase
{
    /**
     * @param list<string> $files one token per character
     */
    #[Test]
    #[DataProvider('provideStreams')]
    public function itMatchesTokenByTokenComparisonWithTheAnchor(array $files): void
    {
        $streams = array_map(self::stream(...), $files);
        $members = [];
        foreach ($files as $file => $text) {
            for ($offset = 0; $offset < \strlen($text); $offset++) {
                if ($text[$offset] === $files[0][0]) {
                    $members[] = PackedPosition::pack($file, $offset);
                }
            }
        }

        self::assertNotSame([], $members);
        $lengths = (new AnchoredPrefixLengths($streams))->of($members, 1);

        $anchor = $files[0];
        $expected = array_map(static function (int $packed) use ($files, $anchor): int {
            $text = $files[PackedPosition::fileIndex($packed)];
            $offset = PackedPosition::offset($packed);
            $length = 0;
            while ($length < \strlen($anchor) && $offset + $length < \strlen($text) && $text[$offset + $length] === $anchor[$length]) {
                $length++;
            }

            return $length;
        }, $members);
        self::assertSame($expected, $lengths);
    }

    #[Test]
    public function itEndsALaterAnchorOnAKeptDiagonalWhereTheKeptMatchEnds(): void
    {
        $run = 'x' . str_repeat('ab', 400) . 'c';
        $lengths = new AnchoredPrefixLengths([self::stream($run . 'd'), self::stream($run . 'e')]);

        self::assertSame([803, 802], $lengths->of([PackedPosition::pack(0, 0), PackedPosition::pack(1, 0)], 1));
        $measured = $lengths->comparisons();
        self::assertSame([801, 800], $lengths->of([PackedPosition::pack(0, 2), PackedPosition::pack(1, 2)], 1));
        self::assertSame($measured + 1, $lengths->comparisons(), 'The second pair must not be compared again');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function provideStreams(): iterable
    {
        yield 'one periodic run' => [['abababababababababc']];
        yield 'nested periods' => [['aabaabaabaacaabaab']];
        yield 'runs in other files' => [['ababababx', 'yabababababab', 'abababab']];
        $random = new Randomizer(new Mt19937(212));
        for ($case = 0; $case < 20; $case++) {
            $files = [];
            for ($file = 0, $count = $random->getInt(1, 3); $file < $count; $file++) {
                $text = 'a';
                for ($i = 0, $length = $random->getInt(5, 60); $i < $length; $i++) {
                    $text .= 'ab'[$random->getInt(0, 9) < 8 ? $i % 2 : $random->getInt(0, 1)];
                }
                $files[] = $text;
            }
            yield "random {$case}" => [$files];
        }
    }

    private static function stream(string $text): TokenStream
    {
        $count = \strlen($text);

        return new TokenStream(str_split($text), 'CCCCC' . str_repeat("\1\1\1\0\1", $count), str_repeat('0', $count));
    }
}
