<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Index;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;

#[CoversClass(PackedPosition::class)]
final class PackedPositionTest extends TestCase
{
    #[Test]
    #[TestWith([0, 1 << 20])]
    #[TestWith([42, 0xffff_ffff])]
    #[TestWith([0x7fff_ffff, 0xffff_ffff])]
    public function itRoundTripsTheFullPackedPositionRange(int $fileIndex, int $offset): void
    {
        $packed = PackedPosition::pack($fileIndex, $offset);

        self::assertSame($fileIndex, PackedPosition::fileIndex($packed));
        self::assertSame($offset, PackedPosition::offset($packed));
        if ($fileIndex === 0x7fff_ffff) {
            self::assertSame(\PHP_INT_MAX, $packed);
        }
    }

    #[Test]
    #[TestWith([-1, 0])]
    #[TestWith([0, -1])]
    #[TestWith([0x8000_0000, 0])]
    #[TestWith([0, 0x1_0000_0000])]
    public function itRejectsPositionsOutsideThePackedRange(int $fileIndex, int $offset): void
    {
        $this->expectException(InvalidArgumentException::class);
        PackedPosition::pack($fileIndex, $offset);
    }

    #[Test]
    public function itRejectsNegativePackedValuesBeforeUnpacking(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PackedPosition::fileIndex(-1);
    }

    #[Test]
    public function itRejectsNegativePackedValuesBeforeReadingTheOffset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PackedPosition::offset(-1);
    }
}
