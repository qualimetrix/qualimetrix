<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDimensionCatalog;

#[CoversClass(HealthDimensionCatalog::class)]
final class HealthDimensionCatalogTest extends TestCase
{
    private HealthDimensionCatalog $provider;

    protected function setUp(): void
    {
        $this->provider = new HealthDimensionCatalog();
    }

    // --- getLabel ---

    // --- getExplanation ---

    // --- getGoodValue ---

    // --- getDirection ---

    // --- getDecomposition ---

    #[Test]
    public function itGetDecompositionKnownDimension(): void
    {
        self::assertSame(['ccn.avg', 'cognitive.avg', 'ccn.p95', 'cognitive.p95'], $this->provider->getDecomposition('health.complexity'));
        self::assertSame(['tcc.avg', 'lcom.avg'], $this->provider->getDecomposition('health.cohesion'));
        self::assertSame(['ce.avg', 'ce_packages.avg', 'distance.avg'], $this->provider->getDecomposition('health.coupling'));
        self::assertSame(['typeCoverage.pct'], $this->provider->getDecomposition('health.typing'));
        self::assertSame(['mi.avg', 'mi.p5', 'mi.min'], $this->provider->getDecomposition('health.maintainability'));
        self::assertSame([], $this->provider->getDecomposition('health.overall'));
        self::assertSame(
            [
                'primaryValue' => 12.0,
                'contributorMetrics' => ['ccn.sum' => 12, 'cognitive.sum' => 8],
            ],
            $this->provider->selectContributorMetrics(
                [
                    ['classKey' => 'ccn.sum', 'direction' => 'lower'],
                    ['classKey' => 'cognitive.sum', 'direction' => 'lower'],
                    ['classKey' => 'missing', 'direction' => 'lower'],
                ],
                static fn(string $key): ?int => [
                    'ccn.sum' => 12,
                    'cognitive.sum' => 8,
                ][$key] ?? null,
            ),
        );
    }

    #[Test]
    public function itGetDecompositionUnknownDimension(): void
    {
        self::assertSame([], $this->provider->getDecomposition('health.unknown'));
    }

    // --- getScoreLabel ---

    /**
     * @return iterable<string, array{float, float, float, string}>
     */
    public static function scoreLabelProvider(): iterable
    {
        // score, warnThreshold, errThreshold, expected
        // range = 100 - 70 = 30
        // strongThreshold = 70 + 30 * 0.6 = 88
        // goodThreshold = 70 + 30 * 0.3 = 79
        yield 'Excellent: well above strong threshold' => [95.0, 70.0, 40.0, 'Excellent'];
        yield 'Excellent: above strong threshold' => [90.0, 70.0, 40.0, 'Excellent'];
        yield 'Excellent: just above strong threshold' => [88.01, 70.0, 40.0, 'Excellent'];
        yield 'Good: exactly strong threshold is not strong' => [88.0, 70.0, 40.0, 'Good'];
        yield 'Good: well above good threshold' => [85.0, 70.0, 40.0, 'Good'];
        yield 'Good: just above good threshold' => [79.01, 70.0, 40.0, 'Good'];
        yield 'Fair: exactly good threshold is not good' => [79.0, 70.0, 40.0, 'Fair'];
        yield 'Fair: above warning' => [75.0, 70.0, 40.0, 'Fair'];
        yield 'Fair: just above warning' => [70.01, 70.0, 40.0, 'Fair'];
        yield 'Poor: exactly warning is not acceptable' => [70.0, 70.0, 40.0, 'Poor'];
        yield 'Poor: above error' => [50.0, 70.0, 40.0, 'Poor'];
        yield 'Poor: just above error' => [40.01, 70.0, 40.0, 'Poor'];
        yield 'Critical: exactly error' => [40.0, 70.0, 40.0, 'Critical'];
        yield 'Critical: below error' => [20.0, 70.0, 40.0, 'Critical'];

        // Test with health.overall defaults (warn=50, err=30):
        // range = 100 - 50 = 50
        // strongThreshold = 50 + 50 * 0.6 = 80
        // goodThreshold = 50 + 50 * 0.3 = 65
        yield 'Overall: Excellent (88.5)' => [88.5, 50.0, 30.0, 'Excellent'];
        yield 'Overall: Good (68.3)' => [68.3, 50.0, 30.0, 'Good'];
        yield 'Overall: Fair (64.6)' => [64.6, 50.0, 30.0, 'Fair'];
        yield 'Overall: Fair (52.5)' => [52.5, 50.0, 30.0, 'Fair'];
        yield 'Overall: Poor (45.4)' => [45.4, 50.0, 30.0, 'Poor'];
    }

    #[DataProvider('scoreLabelProvider')]
    #[Test]
    public function itGetScoreLabel(float $score, float $warnThreshold, float $errThreshold, string $expected): void
    {
        self::assertSame($expected, $this->provider->getScoreLabel($score, $warnThreshold, $errThreshold));
    }

    // --- getHealthDimensionLabel ---

    #[Test]
    public function itGetHealthDimensionLabelBad(): void
    {
        self::assertSame('high complexity', $this->provider->getUnhealthyDimensionLabel('complexity'));
        self::assertSame('low cohesion', $this->provider->getUnhealthyDimensionLabel('cohesion'));
        self::assertSame('high coupling', $this->provider->getUnhealthyDimensionLabel('coupling'));
        self::assertSame('low type safety', $this->provider->getUnhealthyDimensionLabel('typing'));
        self::assertSame('hard to maintain', $this->provider->getUnhealthyDimensionLabel('maintainability'));
    }

    #[Test]
    public function itGetHealthDimensionLabelGood(): void
    {
        self::assertSame('low complexity', $this->provider->getHealthyDimensionLabel('complexity'));
        self::assertSame('good cohesion', $this->provider->getHealthyDimensionLabel('cohesion'));
        self::assertSame('low coupling', $this->provider->getHealthyDimensionLabel('coupling'));
        self::assertSame('good type safety', $this->provider->getHealthyDimensionLabel('typing'));
        self::assertSame('maintainable', $this->provider->getHealthyDimensionLabel('maintainability'));
    }

    #[Test]
    public function itGetHealthDimensionLabelUnknown(): void
    {
        self::assertSame('unknown', $this->provider->getUnhealthyDimensionLabel('unknown'));
        self::assertSame('unknown', $this->provider->getHealthyDimensionLabel('unknown'));
    }

}
