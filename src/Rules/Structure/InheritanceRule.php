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
 * Rule that checks DIT (Depth of Inheritance Tree) at class level.
 *
 * DIT measures how deep a class is in the inheritance hierarchy:
 * - Deep inheritance increases coupling and complexity
 * - Prefer composition over deep inheritance
 */
final class InheritanceRule extends AbstractRule
{
    public const string NAME = 'design.inheritance';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Depth of Inheritance Tree (deep hierarchies increase complexity)';
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
        return [MetricName::STRUCTURE_DIT];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof InheritanceOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $dit = $metrics->get(MetricName::STRUCTURE_DIT);

            if ($dit === null) {
                continue;
            }

            $ditValue = (int) $dit;
            /** @var InheritanceOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $classInfo->file, $classInfo->line ?? 1);
            $severity = $effectiveOptions->getSeverity($ditValue);

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
                        'DIT (Depth of Inheritance) is %d, exceeds threshold of %d. Prefer composition over deep inheritance',
                        $ditValue,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: $ditValue,
                    recommendation: \sprintf('DIT: %d (threshold: %d) — deep inheritance, fragile hierarchy', $ditValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<InheritanceOptions>
     */
    public static function getOptionsClass(): string
    {
        return InheritanceOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'dit-warning' => 'warning',
            'dit-error' => 'error',
        ];
    }
}
