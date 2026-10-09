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

    #[Test]
    public function itReadsTheCompleteOrderedMeanOnTheNativeAst(): void
    {
        $terms = WeightedHealthFormula::termsOf(
            $this->expression,
            'clamp(weighted_mean(m ["health.a"], 0.4, m["health.b"], 0.6), 0, 100)',
        );

        self::assertSame(
            ['health.a' => ['weight' => 0.4], 'health.b' => ['weight' => 0.6]],
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
            'clamp(weighted_mean(m["health.a"], 0.4, max(m["health.b"], 0), 0.6), 0, 100)',
        ));
    }

    #[Test]
    public function itReadsNoWeightedSumOutOfAFormulaThatIsNotOne(): void
    {
        self::assertNull(WeightedHealthFormula::termsOf($this->expression, 'min(m["health.a"], m["health.b"])'));
    }
    #[Test]
    public function itRefusesPartialDuplicateOrInvalidCanonicalTerms(): void
    {
        foreach ([
            'weighted_mean(m["health.a"], 1, m["health.a"], 2)',
            'weighted_mean(m["health.a"], 0)',
            'weighted_mean(m["health.a"], null)',
            'weighted_mean(m["health.a"], "1")',
            'weighted_mean(m["health.a"], 1e999)',
            'weighted_mean(m["health.a"], 1, m["health.b"])',
            'weighted_mean(m["other.a"], 1)',
            'clamp(weighted_mean(m["health.a"], 1), 1, 100)',
            'clamp(weighted_mean(m["health.a"], 1), 0, 200)',
            '(m["health.a"] ?? 75) * 1',
        ] as $formula) {
            self::assertNull(WeightedHealthFormula::termsOf($this->expression, $formula), $formula);
        }
    }

}
