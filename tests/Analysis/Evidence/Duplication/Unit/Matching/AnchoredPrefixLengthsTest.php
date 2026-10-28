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
        [$lengths] = AnchoredPrefixLengths::of($streams, $members, 1);

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

    /** @return iterable<string, array{list<string>}> */
    public static function provideStreams(): iterable
    {
        yield 'one periodic run' => [['abababababababababc']];
        yield 'nested periods' => [['aabaabaabaacaabaab']];
        yield 'runs in other files' => [['ababababx', 'yabababababab', 'abababab']];
        mt_srand(212);
        for ($case = 0; $case < 20; $case++) {
            $files = [];
            for ($file = 0, $count = mt_rand(1, 3); $file < $count; $file++) {
                $text = 'a';
                for ($i = 0, $length = mt_rand(5, 60); $i < $length; $i++) {
                    $text .= 'ab'[mt_rand(0, 9) < 8 ? $i % 2 : mt_rand(0, 1)];
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
