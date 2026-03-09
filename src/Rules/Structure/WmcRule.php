<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Structure;

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
    private const string METRIC_WMC = 'wmc';
    private const string METRIC_IS_DATA_CLASS = 'isDataClass';

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
        return [self::METRIC_WMC, self::METRIC_IS_DATA_CLASS];
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
            if ($this->options->excludeDataClasses && $metrics->get(self::METRIC_IS_DATA_CLASS) === 1) {
                continue;
            }

            $wmc = $metrics->get(self::METRIC_WMC);

            if ($wmc === null) {
                continue;
            }

            $wmcValue = (int) $wmc;
            $severity = $this->options->getSeverity($wmcValue);

            if ($severity !== null) {
                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf(
                        'WMC (Weighted Methods per Class) is %d, exceeds threshold of %d. Simplify methods or split the class',
                        $wmcValue,
                        $severity === Severity::Error
                            ? $this->options->error
                            : $this->options->warning,
                    ),
                    severity: $severity,
                    metricValue: $wmcValue,
                );
            }
        }

        return $violations;
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
