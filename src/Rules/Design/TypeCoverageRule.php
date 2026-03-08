<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Design;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

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
        return ['typeCoverage'];
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

            // Check parameter type coverage
            $paramTotal = $metrics->get('typeCoverage.paramTotal');
            if ($paramTotal !== null && (int) $paramTotal > 0) {
                $paramCoverage = (float) ($metrics->get('typeCoverage.param') ?? 0.0);
                $paramSeverity = $this->options->getParamSeverity($paramCoverage);

                if ($paramSeverity !== null) {
                    $threshold = $paramSeverity === Severity::Error
                        ? $this->options->paramError
                        : $this->options->paramWarning;

                    $violations[] = new Violation(
                        location: new Location($classInfo->file, $classInfo->line),
                        symbolPath: $classInfo->symbolPath,
                        ruleName: $this->getName(),
                        violationCode: self::NAME . '.param',
                        message: \sprintf(
                            'Parameter type coverage is %.1f%% (minimum: %.1f%%). Add type declarations to method parameters',
                            $paramCoverage,
                            $threshold,
                        ),
                        severity: $paramSeverity,
                        metricValue: $paramCoverage,
                    );
                }
            }

            // Check return type coverage
            $returnTotal = $metrics->get('typeCoverage.returnTotal');
            if ($returnTotal !== null && (int) $returnTotal > 0) {
                $returnCoverage = (float) ($metrics->get('typeCoverage.return') ?? 0.0);
                $returnSeverity = $this->options->getReturnSeverity($returnCoverage);

                if ($returnSeverity !== null) {
                    $threshold = $returnSeverity === Severity::Error
                        ? $this->options->returnError
                        : $this->options->returnWarning;

                    $violations[] = new Violation(
                        location: new Location($classInfo->file, $classInfo->line),
                        symbolPath: $classInfo->symbolPath,
                        ruleName: $this->getName(),
                        violationCode: self::NAME . '.return',
                        message: \sprintf(
                            'Return type coverage is %.1f%% (minimum: %.1f%%). Add return type declarations to methods',
                            $returnCoverage,
                            $threshold,
                        ),
                        severity: $returnSeverity,
                        metricValue: $returnCoverage,
                    );
                }
            }

            // Check property type coverage
            $propertyTotal = $metrics->get('typeCoverage.propertyTotal');
            if ($propertyTotal !== null && (int) $propertyTotal > 0) {
                $propertyCoverage = (float) ($metrics->get('typeCoverage.property') ?? 0.0);
                $propertySeverity = $this->options->getPropertySeverity($propertyCoverage);

                if ($propertySeverity !== null) {
                    $threshold = $propertySeverity === Severity::Error
                        ? $this->options->propertyError
                        : $this->options->propertyWarning;

                    $violations[] = new Violation(
                        location: new Location($classInfo->file, $classInfo->line),
                        symbolPath: $classInfo->symbolPath,
                        ruleName: $this->getName(),
                        violationCode: self::NAME . '.property',
                        message: \sprintf(
                            'Property type coverage is %.1f%% (minimum: %.1f%%). Add type declarations to properties',
                            $propertyCoverage,
                            $threshold,
                        ),
                        severity: $propertySeverity,
                        metricValue: $propertyCoverage,
                    );
                }
            }
        }

        return $violations;
    }
}
