<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;

#[CoversClass(DecompositionItem::class)]
final class DecompositionItemTest extends TestCase
{
    #[Test]
    public function itRefusesAWriteToAConstructedItem(): void
    {
        $item = new DecompositionItem(
            metricKey: 'complexity.ccn.avg',
            humanName: 'Cyclomatic (avg)',
            value: 3.5,
            goodValue: 'below 4',
            direction: 'lower_is_better',
            explanation: 'manageable branching',
        );

        self::expectException(Error::class);
        self::expectExceptionMessage('Cannot modify readonly property');

        // @phpstan-ignore assign.propertyProtectedSet
        $item->value = 4.5;
    }
}
