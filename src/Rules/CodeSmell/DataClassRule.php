<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Rule that detects Data Classes — classes with high public surface but low complexity.
 *
 * A Data Class has many public accessors (high WOC) but simple logic (low WMC),
 * suggesting it only holds data without encapsulating behavior.
 * Pure DTOs (readonly, promoted-properties-only, or marked as data class) are excluded.
 */
final class DataClassRule extends AbstractRule
{
    public const string NAME = 'code-smell.data-class';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects classes with high public surface but low complexity (Data Classes)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::CodeSmell;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [
            MetricName::STRUCTURE_WOC,
            MetricName::STRUCTURE_WMC,
            MetricName::STRUCTURE_METHOD_COUNT,
            MetricName::STRUCTURE_IS_READONLY,
            MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY,
            MetricName::STRUCTURE_IS_DATA_CLASS,
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof DataClassOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            // Skip readonly classes if configured
            if ($this->options->excludeReadonly && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1) {
                continue;
            }

            // Skip promoted-properties-only classes if configured
            if ($this->options->excludePromotedOnly && $metrics->get(MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY) === 1) {
                continue;
            }

            // Skip classes with too few methods
            $methodCount = (int) ($metrics->get(MetricName::STRUCTURE_METHOD_COUNT) ?? 0);
            if ($methodCount < $this->options->minMethods) {
                continue;
            }

            // Skip intentional data classes (pure DTOs)
            if ($metrics->get(MetricName::STRUCTURE_IS_DATA_CLASS) === 1) {
                continue;
            }

            $woc = $metrics->get(MetricName::STRUCTURE_WOC);
            if ($woc === null) {
                continue;
            }

            $wocValue = (int) $woc;
            $wmcValue = (int) ($metrics->get(MetricName::STRUCTURE_WMC) ?? 0);

            // Data Class: high WOC (public surface) + low WMC (complexity)
            if ($wocValue >= $this->options->wocThreshold && $wmcValue <= $this->options->wmcThreshold) {
                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf(
                        'Data Class detected: high public surface (WOC=%d%%, threshold %d%%) with low complexity (WMC=%d, threshold %d). Consider encapsulating behavior or using a DTO pattern',
                        $wocValue,
                        $this->options->wocThreshold,
                        $wmcValue,
                        $this->options->wmcThreshold,
                    ),
                    severity: Severity::Warning,
                    metricValue: $wocValue,
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<DataClassOptions>
     */
    public static function getOptionsClass(): string
    {
        return DataClassOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'data-class-woc-threshold' => 'wocThreshold',
            'data-class-wmc-threshold' => 'wmcThreshold',
            'data-class-min-methods' => 'minMethods',
            'data-class-exclude-readonly' => 'excludeReadonly',
            'data-class-exclude-promoted-only' => 'excludePromotedOnly',
        ];
    }
}
