<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Size;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that checks number of methods per class.
 *
 * Too many methods indicate a class may be doing too much
 * and should be split into smaller focused classes.
 */
final class MethodCountRule extends AbstractRule
{
    public const string NAME = 'size.method-count';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks number of methods per class';
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
        return [MetricName::STRUCTURE_METHOD_COUNT];
    }

    /**
     * @return class-string<MethodCountOptions>
     */
    public static function getOptionsClass(): string
    {
        return MethodCountOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'method-count-warning' => 'warning',
            'method-count-error' => 'error',
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof MethodCountOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $methodCount = $metrics->get(MetricName::STRUCTURE_METHOD_COUNT);

            if ($methodCount === null) {
                continue;
            }

            $methodCountValue = (int) $methodCount;
            $severity = $this->options->getSeverity($methodCountValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $this->options->error : $this->options->warning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf('Method count is %d, exceeds threshold of %d. Consider splitting into smaller focused classes', $methodCountValue, $threshold),
                    severity: $severity,
                    metricValue: $methodCountValue,
                    recommendation: \sprintf('Methods: %d (threshold: %d) — too many methods', $methodCountValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }
}
