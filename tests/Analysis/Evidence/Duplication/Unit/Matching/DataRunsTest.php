<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Matching;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DataRuns;
use Qualimetrix\Analysis\Evidence\Duplication\Token\TokenStream;

#[CoversClass(DataRuns::class)]
final class DataRunsTest extends TestCase
{
    #[Test]
    public function itEndsEachPositionAtItsFirstFollowingCodeToken(): void
    {
        $masks = ['0110111', '1100', '0000', '1111'];
        $runs = new DataRuns(array_map(self::stream(...), $masks));

        foreach ($masks as $file => $mask) {
            for ($offset = 0; $offset < \strlen($mask); $offset++) {
                self::assertSame(
                    $offset + strspn($mask, '1', $offset),
                    $runs->endAt(PackedPosition::pack($file, $offset)),
                    "mask {$mask} at {$offset}",
                );
            }
        }
    }

    private static function stream(string $mask): TokenStream
    {
        $count = \strlen($mask);

        return new TokenStream(array_fill(0, $count, 'x'), 'CCCCC' . str_repeat("\1\1\1\0\1", $count), $mask);
    }
}
