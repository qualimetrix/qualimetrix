<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\GroupMeasurement;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FindingFactory;

#[CoversClass(GroupMeasurement::class)]
final class GroupMeasurementTest extends TestCase
{
    #[Test]
    public function itRefusesTheWholeMagnitudeGroupWhenOneMemberIsNotFinite(): void
    {
        $symbol = SymbolPath::forFile(RelativePath::fromString('src/A.php'));
        $measurement = GroupMeasurement::fromFindings([
            FindingFactory::magnitude($symbol, 12),
            FindingFactory::magnitude($symbol, \INF),
        ], false);

        self::assertSame(2, $measurement->count);
        self::assertSame(1, $measurement->membersWithoutMagnitude);
        self::assertNull($measurement->magnitudes);
        self::assertFalse($measurement->complete());
    }

    #[Test]
    public function itCountsOccurrenceMembersRegardlessOfMetricValue(): void
    {
        $symbol = SymbolPath::forFile(RelativePath::fromString('src/A.php'));
        $measurement = GroupMeasurement::fromFindings([
            FindingFactory::magnitude($symbol, \NAN),
            FindingFactory::magnitude($symbol, 0),
        ], true);

        self::assertSame(2, $measurement->count);
        self::assertSame(0, $measurement->membersWithoutMagnitude);
        self::assertNull($measurement->magnitudes);
        self::assertTrue($measurement->complete());
    }
}
