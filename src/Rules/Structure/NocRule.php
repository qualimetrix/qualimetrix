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
 * Rule that checks NOC (Number of Children) at class level.
 *
 * NOC measures how many classes directly extend (inherit from) a given class.
 * High NOC indicates:
 * - Wide reuse through inheritance
 * - High impact of changes (affects many subclasses)
 * - Potential need for interface instead of class inheritance
 * - Possible violation of Liskov Substitution Principle
 */
final class NocRule extends AbstractRule
{
    public const string NAME = 'design.noc';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Number of Children (many direct subclasses indicate wide impact)';
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
        return [MetricName::STRUCTURE_NOC];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof NocOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $noc = $metrics->get(MetricName::STRUCTURE_NOC);

            if ($noc === null || $noc === 0) {
                continue;
            }

            $nocValue = (int) $noc;
            /** @var NocOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $classInfo->file, $classInfo->line ?? 1);
            $severity = $effectiveOptions->getSeverity($nocValue);

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
                        'NOC (Number of Children) is %d, exceeds threshold of %d. Consider using interfaces instead of inheritance',
                        $nocValue,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: $nocValue,
                    recommendation: \sprintf('NOC: %d (threshold: %d) — too many direct subclasses', $nocValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<NocOptions>
     */
    public static function getOptionsClass(): string
    {
        return NocOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'noc-warning' => 'warning',
            'noc-error' => 'error',
        ];
    }
}
