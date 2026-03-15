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
            $severity = $this->options->getSeverity($nocValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error
                    ? $this->options->error
                    : $this->options->warning;

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
                    humanMessage: \sprintf('NOC: %d (max %d) — too many direct subclasses', $nocValue, $threshold),
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
