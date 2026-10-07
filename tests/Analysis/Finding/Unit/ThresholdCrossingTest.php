<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;

#[CoversClass(ThresholdCrossing::class)]
final class ThresholdCrossingTest extends TestCase
{
    #[Test]
    #[DataProvider('crossings')]
    public function itNamesTheRawNumericCrossing(int|float $value, int|float $threshold, ThresholdCrossing $expected): void
    {
        self::assertSame($expected, ThresholdCrossing::of($value, $threshold));
    }

    /** @return iterable<string, array{int|float, int|float, ThresholdCrossing}> */
    public static function crossings(): iterable
    {
        yield 'integer equality' => [15, 15, ThresholdCrossing::Reaches];
        yield 'mixed numeric equality' => [15, 15.0, ThresholdCrossing::Reaches];
        yield 'strictly above' => [16, 15, ThresholdCrossing::Exceeds];
        yield 'same displayed precision' => [0.5049, 0.5, ThresholdCrossing::Exceeds];
    }

    #[Test]
    public function itRefusesValuesBelowTheSelectedThreshold(): void
    {
        self::expectException(LogicException::class);
        ThresholdCrossing::of(14, 15);
    }
}
