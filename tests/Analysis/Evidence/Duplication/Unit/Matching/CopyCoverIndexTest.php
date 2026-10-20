<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Matching;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\CopyCoverIndex;

#[CoversClass(CopyCoverIndex::class)]
final class CopyCoverIndexTest extends TestCase
{
    #[Test]
    public function itFindsOnlyWholeTokenCoversInTheRequestedFile(): void
    {
        $index = new CopyCoverIndex();
        $index->add(1, 0, 10, 20);
        $index->add(2, 0, 0, 30);
        $index->add(3, 1, 0, 30);
        $index->add(4, 0, 20, 30);
        $out = [999];
        $index->containing(0, 10, 20, $out);
        self::assertSame([2, 1], $out);
        $index->containing(0, 19, 21, $out);
        self::assertSame([2], $out);
        $index->containing(2, 10, 20, $out);
        self::assertSame([], $out);
    }
}
