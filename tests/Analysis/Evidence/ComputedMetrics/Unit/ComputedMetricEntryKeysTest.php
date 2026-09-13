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
    #[Test]
    public function itDeclaresTheNineEntryKeys(): void
    {
        $accepted = ComputedMetricEntryKeys::acceptedEntryKeys();

        self::assertSame(
            ['description', 'enabled', 'error', 'formula', 'formulas', 'inverted', 'levels', 'threshold', 'warning'],
            $accepted->acceptedForDisplay(),
        );
    }

    #[Test]
    public function itDeclaresTheThreeFormulaKeys(): void
    {
        self::assertSame(
            ['class', 'namespace', 'project'],
            ComputedMetricEntryKeys::acceptedFormulaKeys()->acceptedForDisplay(),
        );
    }

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

    /**
     * The reserved `health.` prefix is always its own segment, a user-chosen
     * name is one
     * opaque segment — the test that pins the two cases stay distinguishable
     * by segment count and closedness downstream.
     */
    #[Test]
    public function itSlicesAHealthNameAtTheReservedPrefix(): void
    {
        self::assertSame(
            ['computed_metrics', 'health', 'complexity'],
            ComputedMetricEntryKeys::nameSegments('health.complexity'),
        );
    }

    #[Test]
    public function itKeepsAUserChosenNameAsOneOpaqueSegment(): void
    {
        self::assertSame(
            ['computed_metrics', 'computed.branch-load'],
            ComputedMetricEntryKeys::nameSegments('computed.branch-load'),
        );
    }
}
