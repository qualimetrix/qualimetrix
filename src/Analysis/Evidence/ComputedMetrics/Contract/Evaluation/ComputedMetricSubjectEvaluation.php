<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Throwable;

/** Pure formula evaluation over one exact subject's supplied input values. */
final readonly class ComputedMetricSubjectEvaluation
{
    public function __construct(private ComputedMetricExpression $expression = new ComputedMetricExpression()) {}

    /** @param array<string, int|float|string|bool|null> $values */
    public function evaluate(ComputedMetricDefinition $definition, SymbolLevel $level, array $values): ComputedMetricOutcome
    {
        try {
            if (!$definition->getApplicabilityForLevel($level)->appliesTo($values)) {
                return ComputedMetricOutcome::notApplicable();
            }
            $formula = $definition->getFormulaForLevel($level);
            if ($formula === null) {
                return ComputedMetricOutcome::noValue();
            }
            [$missing, $value] = $this->expression->evaluateOn($formula, new MetricLookup($values));
            if ($missing !== []) {
                return ComputedMetricOutcome::missingKeys($missing);
            }

            return self::resultOf($value);
        } catch (Throwable $failure) {
            return ComputedMetricOutcome::failure($failure->getMessage());
        }
    }

    private static function resultOf(mixed $value): ComputedMetricOutcome
    {
        if ($value === null) {
            return ComputedMetricOutcome::noValue();
        }
        if (!\is_int($value) && !\is_float($value)) {
            return ComputedMetricOutcome::failure('Computed formula must return a finite number or null.');
        }

        return ComputedMetricOutcome::value($value);
    }
}
