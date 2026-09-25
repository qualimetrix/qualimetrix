<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeys;

#[CoversClass(ComputedMetricEntryKeys::class)]
final class ComputedMetricEntryKeysTest extends TestCase
{
    /**
     * Six names, sorted — the sixth is `health.overall` itself, which is
     * absent from today's printed list because it used to be built from
     * `HealthDimension::subDimensions()` rather than `::cases()`.
     */
    #[Test]
    public function itDeclaresSixSortedHealthNamesIncludingOverall(): void
    {
        self::assertSame(
            ['cohesion', 'complexity', 'coupling', 'maintainability', 'overall', 'typing'],
            ComputedMetricEntryKeys::acceptedHealthNames(),
        );
    }
}
