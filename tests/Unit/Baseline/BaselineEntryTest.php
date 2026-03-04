<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline;

use AiMessDetector\Baseline\BaselineEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaselineEntry::class)]
final class BaselineEntryTest extends TestCase
{
    public function testFromArrayCreatesEntry(): void
    {
        $data = [
            'rule' => 'complexity',
            'hash' => 'a1b2c3d4',
        ];

        $entry = BaselineEntry::fromArray($data);

        self::assertSame('complexity', $entry->rule);
        self::assertSame('a1b2c3d4', $entry->hash);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $entry = new BaselineEntry(
            rule: 'complexity',
            hash: 'a1b2c3d4',
        );

        $array = $entry->toArray();

        $expected = [
            'rule' => 'complexity',
            'hash' => 'a1b2c3d4',
        ];

        self::assertSame($expected, $array);
    }

    public function testRoundTripConversion(): void
    {
        $original = new BaselineEntry(
            rule: 'coupling',
            hash: 'e5f6g7h8',
        );

        $array = $original->toArray();
        $restored = BaselineEntry::fromArray($array);

        self::assertEquals($original, $restored);
    }
}
