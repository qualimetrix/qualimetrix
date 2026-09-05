<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\TypeCoverage;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;

/**
 * How many of a class's declared properties carry a type declaration, against a
 * configured minimum.
 *
 * Sibling of {@see ParamTypeCoverageRule} and
 * {@see ReturnTypeCoverageRule}, configured and suppressed on its own.
 *
 * @qmx-ignore health.cohesion -- Metadata only: every method here returns a constant naming this dimension, so no two of them can share a field. The judgement they configure lives in AbstractTypeCoverageRule.
 */
#[CliAlias('property-type-coverage-warning', 'warning')]
#[CliAlias('property-type-coverage-error', 'error')]
final class PropertyTypeCoverageRule extends AbstractTypeCoverageRule
{
    public const string NAME = 'design.type-coverage.property';

    public function getDescription(): string
    {
        return 'Checks type coverage of properties per class';
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
        return MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL;
    }

    protected static function coverageMetric(): string
    {
        return MetricName::DESIGN_TYPE_COVERAGE_PROPERTY;
    }

    protected function label(): string
    {
        return 'Property';
    }

    protected function hint(): string
    {
        return 'Add type declarations to properties';
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
