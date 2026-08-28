<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\WeightedHealthFormula;

/**
 * The shape `--exclude-health` rebuilds, and the shapes it must refuse to
 * rebuild rather than half-read.
 */
#[CoversClass(WeightedHealthFormula::class)]
final class WeightedHealthFormulaTest extends TestCase
{
    private ComputedMetricExpression $expression;

    protected function setUp(): void
    {
        $this->expression = new ComputedMetricExpression();
    }

    /**
     * The weighted sum `--exclude-health` rebuilds, read off the tree: the
     * pattern this replaced accepted neither a space nor a fractional fallback
     * nor a reversed factor order, and reported a partial read as a whole one.
     */
    #[Test]
    public function itReadsAWeightedSumInEveryShapeTheLanguageAccepts(): void
    {
        $terms = WeightedHealthFormula::termsOf(
            $this->expression,
            'clamp((m ["health.a"] ?? 75.5) * 0.4 + 0.6 * (m["health.b"] ?? 75), 0, 100)',
        );

        self::assertSame(
            ['health.a' => ['weight' => 0.4, 'fallback' => 75.5], 'health.b' => ['weight' => 0.6, 'fallback' => 75.0]],
            $terms,
        );
    }

    /**
     * A term it cannot read makes the whole answer null. A partial read that
     * looked complete dropped a dimension and renormalised the rest around it.
     */
    #[Test]
    public function itReadsNoWeightedSumAtAllWhenOneTermIsNotOne(): void
    {
        self::assertNull(WeightedHealthFormula::termsOf(
            $this->expression,
            'clamp((m["health.a"] ?? 75) * 0.4 + m["health.b"], 0, 100)',
        ));
    }

    #[Test]
    public function itReadsNoWeightedSumOutOfAFormulaThatIsNotOne(): void
    {
        self::assertNull(WeightedHealthFormula::termsOf($this->expression, 'min(m["health.a"], m["health.b"])'));
    }
}
