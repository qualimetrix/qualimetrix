<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Design;

use LogicException;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that checks type coverage per class.
 *
 * Produces up to 3 violations per class:
 * - Parameter type coverage below threshold
 * - Return type coverage below threshold
 * - Property type coverage below threshold
 */
final class TypeCoverageRule extends AbstractRule
{
    public const string NAME = 'design.type-coverage';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks type coverage of parameters, return types, and properties per class';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Design;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::TYPE_COVERAGE_PARAM];
    }

    /**
     * @return class-string<TypeCoverageOptions>
     */
    public static function getOptionsClass(): string
    {
        return TypeCoverageOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'type-coverage-param-warning' => 'param_warning',
            'type-coverage-param-error' => 'param_error',
            'type-coverage-return-warning' => 'return_warning',
            'type-coverage-return-error' => 'return_error',
            'type-coverage-property-warning' => 'property_warning',
            'type-coverage-property-error' => 'property_error',
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof TypeCoverageOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            $location = new Location($classInfo->file, $classInfo->line);

            $paramViolation = $this->checkCoverage(
                $metrics,
                $classInfo->symbolPath,
                $location,
                MetricName::TYPE_COVERAGE_PARAM_TOTAL,
                MetricName::TYPE_COVERAGE_PARAM,
                'Parameter',
                $this->options->paramWarning,
                $this->options->paramError,
            );

            if ($paramViolation !== null) {
                $violations[] = $paramViolation;
            }

            $returnViolation = $this->checkCoverage(
                $metrics,
                $classInfo->symbolPath,
                $location,
                MetricName::TYPE_COVERAGE_RETURN_TOTAL,
                MetricName::TYPE_COVERAGE_RETURN,
                'Return',
                $this->options->returnWarning,
                $this->options->returnError,
            );

            if ($returnViolation !== null) {
                $violations[] = $returnViolation;
            }

            $propertyViolation = $this->checkCoverage(
                $metrics,
                $classInfo->symbolPath,
                $location,
                MetricName::TYPE_COVERAGE_PROPERTY_TOTAL,
                MetricName::TYPE_COVERAGE_PROPERTY,
                'Property',
                $this->options->propertyWarning,
                $this->options->propertyError,
            );

            if ($propertyViolation !== null) {
                $violations[] = $propertyViolation;
            }
        }

        return $violations;
    }

    private function checkCoverage(
        MetricBag $metrics,
        SymbolPath $symbolPath,
        Location $location,
        string $totalMetric,
        string $coverageMetric,
        string $label,
        float $warningThreshold,
        float $errorThreshold,
    ): ?Violation {
        $total = $metrics->get($totalMetric);

        if ($total === null || (int) $total <= 0) {
            return null;
        }

        $coverage = (float) ($metrics->get($coverageMetric) ?? 0.0);

        if ($coverage < $errorThreshold) {
            $severity = Severity::Error;
            $threshold = $errorThreshold;
        } elseif ($coverage < $warningThreshold) {
            $severity = Severity::Warning;
            $threshold = $warningThreshold;
        } else {
            return null;
        }

        [$code, $hint] = match ($label) {
            'Parameter' => ['param', 'Add type declarations to method parameters'],
            'Return' => ['return', 'Add return type declarations to methods'],
            'Property' => ['property', 'Add type declarations to properties'],
            default => throw new LogicException(\sprintf('Unknown coverage label: %s', $label)),
        };

        return new Violation(
            location: $location,
            symbolPath: $symbolPath,
            ruleName: $this->getName(),
            violationCode: self::NAME . '.' . $code,
            message: \sprintf(
                '%s type coverage is %.1f%% (minimum: %.1f%%). %s',
                $label,
                $coverage,
                $threshold,
                $hint,
            ),
            severity: $severity,
            metricValue: $coverage,
            recommendation: \sprintf('%s type coverage: %.1f%% (threshold: %.1f%%) — missing type declarations', $label, $coverage, $threshold),
            threshold: $threshold,
        );
    }
}
