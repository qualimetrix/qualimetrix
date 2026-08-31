<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\TypeCoverage;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;

/**
 * How many of a class's method and function return types carry a type declaration, against a
 * configured minimum.
 *
 * Sibling of {@see ParamTypeCoverageRule} and
 * {@see PropertyTypeCoverageRule}, configured and suppressed on its own.
 *
 * @qmx-ignore health.cohesion -- Metadata only: every method here returns a constant naming this dimension, so no two of them can share a field. The judgement they configure lives in AbstractTypeCoverageRule.
 */
#[CliAlias('return-type-coverage-warning', 'warning')]
#[CliAlias('return-type-coverage-error', 'error')]
final class ReturnTypeCoverageRule extends AbstractTypeCoverageRule
{
    public const string NAME = 'design.type-coverage.return';

    public function getDescription(): string
    {
        return 'Checks type coverage of return types per class';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::DESIGN_TYPE_COVERAGE_RETURN];
    }

    /**
     * @return class-string<TypeCoverageOptions>
     */
    public static function getOptionsClass(): string
    {
        return TypeCoverageOptions::class;
    }

    protected static function channelName(): string
    {
        return self::NAME;
    }

    protected function totalMetric(): string
    {
        return MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL;
    }

    protected function coverageMetric(): string
    {
        return MetricName::DESIGN_TYPE_COVERAGE_RETURN;
    }

    protected function label(): string
    {
        return 'Return';
    }

    protected function hint(): string
    {
        return 'Add return type declarations to methods';
    }

    public const string DOCS_PAGE = 'rules/design.md';

    public const int REMEDIATION_MINUTES = 15;

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
