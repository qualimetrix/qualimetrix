<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\CoverageUnit;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;

/**
 * A published score says what share of its subject it was computed over.
 *
 * The numbers below are the php-parser shape, so a reader can check them
 * against a run: 142 of 268 class symbols carry TCC, 260 carry LCOM, and
 * cohesion must report the narrow one.
 */
#[CoversClass(HealthSummaryBuilder::class)]
#[CoversClass(HealthCoverage::class)]
final class HealthScoreCoverageTest extends TestCase
{
    use MetricRepositoryTestHelper;

    #[Test]
    public function itReportsTheNarrowestInputRatherThanTheWidest(): void
    {
        $cohesion = $this->build([
            'health.cohesion' => 51.7,
            'cohesion.tcc.avg' => 0.17,
            'cohesion.tcc.count' => 142,
            'cohesion.lcom.avg' => 1.75,
            'cohesion.lcom.count' => 260,
            'size.symbol-class-count' => 268,
        ])['cohesion']->coverage;

        self::assertTrue($cohesion->applicable);
        self::assertSame(142, $cohesion->measured);
        self::assertSame(268, $cohesion->eligible);
        self::assertSame(142 / 268, $cohesion->ratio);
        self::assertSame(CoverageUnit::Classes, $cohesion->unit);
        self::assertSame('cohesion.tcc.count', $cohesion->basis);
        self::assertNull($cohesion->reason);
    }

    #[Test]
    public function itCountsCallablesForCallableAggregates(): void
    {
        $maintainability = $this->build([
            'health.maintainability' => 95.5,
            'maintainability.mi.avg' => 84.6,
            'maintainability.mi.count' => 2263,
            'size.symbol-method-count' => 2263,
            'size.symbol-class-count' => 268,
        ])['maintainability']->coverage;

        self::assertSame(2263, $maintainability->measured);
        self::assertSame(2263, $maintainability->eligible);
        self::assertSame(CoverageUnit::Callables, $maintainability->unit);
    }

    #[Test]
    public function itCountsDeclaringNamespacesForANamespaceCollectedAggregate(): void
    {
        $coupling = $this->build(
            [
                'health.coupling' => 44.2,
                'coupling.distance-own.avg' => 0.49,
                'coupling.distance-own.count' => 14,
                'coupling.cbo.avg' => 9.9,
                'coupling.cbo.count' => 268,
                'size.symbol-class-count' => 268,
                'size.symbol-declaring-namespace-count' => 20,
            ],
        )['coupling']->coverage;

        self::assertSame(14, $coupling->measured);
        self::assertSame(20, $coupling->eligible);
        self::assertSame(CoverageUnit::DeclaringNamespaces, $coupling->unit);
        self::assertSame('coupling.distance-own.count', $coupling->basis);
    }

    /**
     * The denominator has to come from somewhere the aggregate's own walk
     * cannot reach, or the walk's losses are invisible. The leaf-only fold
     * dropped a namespace that both declares types and has sub-namespaces, and
     * with the tree as the denominator that namespace left both sides of the
     * ratio and the line read 100%. The fold now reaches it, so the numbers
     * below are a shape the product no longer produces — which is the point:
     * any future loss has to show here rather than cancel out.
     */
    #[Test]
    public function itShowsANamespaceTheAggregateNeverReachedAsAGap(): void
    {
        $coupling = $this->build(
            [
                'health.coupling' => 44.2,
                'coupling.distance-own.avg' => 0.49,
                // What a fold that lost a namespace saw: three of the four.
                'coupling.distance-own.count' => 3,
                'coupling.cbo.avg' => 9.9,
                'coupling.cbo.count' => 268,
                'size.symbol-class-count' => 268,
                // What the run measured: the three leaves plus the leaf-and-parent.
                'size.symbol-declaring-namespace-count' => 4,
            ],
            // The tree the old denominator was read from reports three leaves,
            // which is exactly the number the fold produced.
            new NamespaceTree(['Deep\\A', 'Deep\\B', 'Other']),
        )['coupling']->coverage;

        self::assertSame(3, $coupling->measured);
        self::assertSame(4, $coupling->eligible);
        self::assertSame(0.75, $coupling->ratio);
    }

    #[Test]
    public function itPublishesAMeasuredZeroWhenTheAggregateNeverReachedTheProject(): void
    {
        // A project every one of whose namespaces declares only bare enums:
        // each has an abstractness of its own that is undefined, so none
        // publishes a distance, and the aggregator writes no key at all rather
        // than a zero. The formula still read the absent aggregate through
        // `?? 0` and scored the project anyway. Zero coverage is the whole
        // point of publishing it, so it must not be quietly turned into "not
        // applicable" — and it is why an absent `.count` cannot be refused
        // here, the way an absent population is.
        $coupling = $this->build(
            [
                'health.coupling' => 100.0,
                'coupling.cbo.avg' => 1.69,
                'coupling.cbo.count' => 140,
                'size.symbol-class-count' => 140,
                'size.symbol-declaring-namespace-count' => 1,
            ],
        )['coupling']->coverage;

        self::assertTrue($coupling->applicable);
        self::assertSame(0, $coupling->measured);
        self::assertSame(1, $coupling->eligible);
        self::assertSame(0.0, $coupling->ratio);
        self::assertSame('coupling.distance-own.count', $coupling->basis);
    }

    #[Test]
    public function itStatesWhyADimensionHasNoCoverageInsteadOfReportingZero(): void
    {
        $scores = $this->build([
            'health.typing' => 90.5,
            'health.overall' => 75.5,
            'design.type-coverage.param.total.sum' => 1237,
            'design.type-coverage.param.typed.sum' => 1101,
            'size.symbol-class-count' => 268,
        ]);

        foreach (['typing', 'overall'] as $dimension) {
            $coverage = $scores[$dimension]->coverage;
            self::assertFalse($coverage->applicable, $dimension);
            self::assertNull($coverage->measured, $dimension);
            self::assertNull($coverage->ratio, $dimension);
            self::assertNotNull($coverage->reason, $dimension);
        }

        self::assertStringContainsString('no .count is published', $scores['typing']->coverage->reason ?? '');
        self::assertStringContainsString('composes the other dimensions', $scores['overall']->coverage->reason ?? '');
    }

    #[Test]
    public function itRefusesToCallAnEmptyPopulationACoverageOfZero(): void
    {
        $cohesion = $this->build([
            'health.cohesion' => 100.0,
            'cohesion.tcc.count' => 0,
            'size.symbol-class-count' => 0,
        ])['cohesion']->coverage;

        self::assertFalse($cohesion->applicable);
        self::assertSame('no classes were measured', $cohesion->reason);
    }

    /**
     * The other half of the same question. An absent `.count` is a run that
     * measured nothing; an absent population is a denominator this product
     * never wrote, and answering zero turned that into "no classes were
     * measured" — a confident statement about the code, made out of the
     * reader's own failure. The population comes from the symbol list beside
     * the aggregates, so no run publishes a score without it.
     */
    #[Test]
    public function itRefusesAPopulationMetricThisRunNeverPublished(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('size.symbol-class-count');

        $this->build([
            'health.cohesion' => 51.7,
            'cohesion.tcc.avg' => 0.17,
            'cohesion.tcc.count' => 142,
        ]);
    }

    /**
     * @param array<string, int|float> $projectMetrics
     *
     * @return array<string, \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore>
     */
    private function build(array $projectMetrics, ?NamespaceTree $tree = null): array
    {
        $builder = new HealthSummaryBuilder(
            new HealthMetricCatalog(),
            self::createStub(ComputedMetricDefinitionCatalogInterface::class),
        );

        return $builder->build(
            $this->createMetricRepository(projectMetrics: MetricBag::fromArray($projectMetrics)),
            $tree ?? new NamespaceTree([]),
            [],
        )->healthScores;
    }
}
