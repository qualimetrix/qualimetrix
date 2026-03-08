<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleInterface;
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
final class PropertyCountRule extends AbstractRule implements RuleInterface
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
        return ['propertyCount', 'isReadonly', 'isPromotedPropertiesOnly'];
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
            $propertyCount = $metrics->get('propertyCount');

            if ($propertyCount === null) {
                continue;
            }

            // Skip readonly classes if configured
            if ($this->options->excludeReadonly && $metrics->get('isReadonly') === 1) {
                continue;
            }

            // Skip classes with only promoted properties if configured
            if ($this->options->excludePromotedOnly && $metrics->get('isPromotedPropertiesOnly') === 1) {
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
