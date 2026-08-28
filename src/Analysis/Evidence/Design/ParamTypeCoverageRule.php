<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;

/**
 * How many of a class's method and function parameters carry a type declaration, against a
 * configured minimum.
 *
 * A dimension of its own rather than one facet of a single
 * type-coverage rule: a codebase typically types its parameters, its return
 * types and its properties at different speeds, so one threshold and one
 * suppression per dimension is what a project can act on.
 *
 * @qmx-ignore health.cohesion -- Metadata only: every method here returns a constant naming this dimension, so no two of them can share a field. The judgement they configure lives in AbstractTypeCoverageRule.
 */
#[CliAlias('param-type-coverage-warning', 'warning')]
#[CliAlias('param-type-coverage-error', 'error')]
final class ParamTypeCoverageRule extends AbstractTypeCoverageRule
{
    public const string NAME = 'design.type-coverage.param';

    public function getDescription(): string
    {
        return 'Checks type coverage of parameters per class';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::DESIGN_TYPE_COVERAGE_PARAM];
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
        return MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL;
    }

    protected function coverageMetric(): string
    {
        return MetricName::DESIGN_TYPE_COVERAGE_PARAM;
    }

    protected function label(): string
    {
        return 'Parameter';
    }

    protected function hint(): string
    {
        return 'Add type declarations to method parameters';
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
