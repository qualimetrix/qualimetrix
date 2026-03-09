<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Rule that checks if classes have too many properties.
 *
 * Too many properties may indicate a God Class that violates the Single Responsibility Principle.
 */
final class PropertyCountRule extends AbstractRule
{
    public const string NAME = 'size.property-count';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks if classes have too many properties';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Size;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::STRUCTURE_PROPERTY_COUNT, MetricName::STRUCTURE_IS_READONLY, MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY];
    }

    /**
     * @return class-string<PropertyCountOptions>
     */
    public static function getOptionsClass(): string
    {
        return PropertyCountOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'property-count-warning' => 'warning',
            'property-count-error' => 'error',
            'property-exclude-readonly' => 'excludeReadonly',
            'property-exclude-promoted-only' => 'excludePromotedOnly',
        ];
    }

    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof PropertyCountOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $propertyCount = $metrics->get(MetricName::STRUCTURE_PROPERTY_COUNT);

            if ($propertyCount === null) {
                continue;
            }

            // Skip readonly classes if configured
            if ($this->options->excludeReadonly && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1) {
                continue;
            }

            // Skip classes with only promoted properties if configured
            if ($this->options->excludePromotedOnly && $metrics->get(MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY) === 1) {
                continue;
            }

            $propertyCountValue = (int) $propertyCount;
            $severity = $this->options->getSeverity($propertyCountValue);

            if ($severity === null) {
                continue;
            }

            $threshold = $severity === Severity::Error
                ? $this->options->error
                : $this->options->warning;

            $violations[] = new Violation(
                location: new Location($classInfo->file, $classInfo->line),
                symbolPath: $classInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: self::NAME,
                message: \sprintf(
                    'Property count is %d, exceeds threshold of %d. Consider splitting the class or using composition',
                    $propertyCountValue,
                    $threshold,
                ),
                severity: $severity,
                metricValue: $propertyCountValue,
            );
        }

        return $violations;
    }

}
