<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Structure;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that checks LCOM (Lack of Cohesion of Methods) at class level.
 *
 * LCOM measures how well methods in a class work together:
 * - LCOM = 1: all methods share at least one property (cohesive)
 * - LCOM > 1: class could potentially be split into multiple classes
 */
final class LcomRule extends AbstractRule
{
    public const string NAME = 'design.lcom';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Lack of Cohesion of Methods (high values indicate class should be split)';
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
        return [MetricName::STRUCTURE_LCOM, MetricName::STRUCTURE_METHOD_COUNT, MetricName::STRUCTURE_IS_READONLY];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof LcomOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            // Skip readonly classes if configured
            if ($this->options->excludeReadonly && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1) {
                continue;
            }

            // Skip classes with too few methods
            $methodCount = (int) ($metrics->get(MetricName::STRUCTURE_METHOD_COUNT) ?? 0);
            if ($methodCount < $this->options->minMethods) {
                continue;
            }

            $lcom = $metrics->get(MetricName::STRUCTURE_LCOM);

            if ($lcom === null) {
                continue;
            }

            $lcomValue = (int) $lcom;
            /** @var LcomOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $classInfo->file, $classInfo->line ?? 1);
            $severity = $effectiveOptions->getSeverity($lcomValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error
                    ? $effectiveOptions->error
                    : $effectiveOptions->warning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf(
                        'LCOM (Lack of Cohesion) is %d, exceeds threshold of %d. Class could be split into %d cohesive parts',
                        $lcomValue,
                        $threshold,
                        $lcomValue,
                    ),
                    severity: $severity,
                    metricValue: $lcomValue,
                    recommendation: \sprintf('LCOM4: %d (threshold: %d) — class has %d unrelated method groups', $lcomValue, $threshold, $lcomValue),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<LcomOptions>
     */
    public static function getOptionsClass(): string
    {
        return LcomOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'lcom-warning' => 'warning',
            'lcom-error' => 'error',
            'lcom-exclude-readonly' => 'excludeReadonly',
            'lcom-min-methods' => 'minMethods',
        ];
    }
}
