<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Structure;

use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Rule that checks WMC (Weighted Methods per Class) at class level.
 *
 * WMC is the sum of cyclomatic complexities of all methods in a class.
 * It combines size and complexity into a single metric:
 * - WMC <= 30: simple class
 * - WMC 31-50: medium complexity
 * - WMC > 50: complex class requiring refactoring
 */
final class WmcRule extends AbstractRule
{
    public const string NAME = 'complexity.wmc';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Weighted Methods per Class (sum of method complexities)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Complexity;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::STRUCTURE_WMC, MetricName::STRUCTURE_IS_DATA_CLASS, MetricName::STRUCTURE_METHOD_COUNT];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof WmcOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            // Skip data classes if configured
            if ($this->options->excludeDataClasses && $metrics->get(MetricName::STRUCTURE_IS_DATA_CLASS) === 1) {
                continue;
            }

            $wmc = $metrics->get(MetricName::STRUCTURE_WMC);

            if ($wmc === null) {
                continue;
            }

            $wmcValue = (int) $wmc;
            $severity = $this->options->getSeverity($wmcValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error
                    ? $this->options->error
                    : $this->options->warning;

                $methodCount = $metrics->get(MetricName::STRUCTURE_METHOD_COUNT);
                $recommendation = $this->buildRecommendation($wmcValue, $threshold, $methodCount !== null ? (int) $methodCount : null);

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf(
                        'WMC (Weighted Methods per Class) is %d, exceeds threshold of %d. Simplify methods or split the class',
                        $wmcValue,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: $wmcValue,
                    recommendation: $recommendation,
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * Builds a contextual recommendation based on avg method complexity.
     */
    private function buildRecommendation(int $wmcValue, int $threshold, ?int $methodCount): string
    {
        if ($methodCount === null || $methodCount === 0) {
            return \sprintf('WMC: %d (threshold: %d) — weighted method complexity is high', $wmcValue, $threshold);
        }

        $avgCcn = $wmcValue / $methodCount;

        if ($avgCcn < 3.0) {
            return \sprintf(
                'WMC: %d across %d methods (avg %.1f) — many methods, consider splitting the class',
                $wmcValue,
                $methodCount,
                $avgCcn,
            );
        }

        if ($avgCcn >= 5.0) {
            return \sprintf(
                'WMC: %d across %d methods (avg %.1f) — some methods are very complex',
                $wmcValue,
                $methodCount,
                $avgCcn,
            );
        }

        return \sprintf(
            'WMC: %d across %d methods (avg %.1f) — weighted method complexity is high',
            $wmcValue,
            $methodCount,
            $avgCcn,
        );
    }

    /**
     * @return class-string<WmcOptions>
     */
    public static function getOptionsClass(): string
    {
        return WmcOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'wmc-warning' => 'warning',
            'wmc-error' => 'error',
            'wmc-exclude-data-classes' => 'excludeDataClasses',
        ];
    }
}
