<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Health\DecompositionItem;

#[CoversClass(DecompositionItem::class)]
final class DecompositionItemTest extends TestCase
{
    public function testConstruction(): void
    {
        $item = new DecompositionItem(
            metricKey: 'ccn.avg',
            humanName: 'Cyclomatic (avg)',
            value: 3.5,
            goodValue: 'below 4',
            direction: 'lower_is_better',
            explanation: 'manageable branching',
        );

        self::assertSame('ccn.avg', $item->metricKey);
        self::assertSame('Cyclomatic (avg)', $item->humanName);
        self::assertSame(3.5, $item->value);
        self::assertSame('below 4', $item->goodValue);
        self::assertSame('lower_is_better', $item->direction);
        self::assertSame('manageable branching', $item->explanation);
    }
}
