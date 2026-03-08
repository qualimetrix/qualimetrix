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
 * Rule that checks DIT (Depth of Inheritance Tree) at class level.
 *
 * DIT measures how deep a class is in the inheritance hierarchy:
 * - Deep inheritance increases coupling and complexity
 * - Prefer composition over deep inheritance
 */
final class InheritanceRule extends AbstractRule
{
    public const string NAME = 'design.inheritance';
    private const string METRIC_DIT = 'dit';

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
        return [self::METRIC_DIT];
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
            $dit = $metrics->get(self::METRIC_DIT);

            if ($dit === null) {
                continue;
            }

            $ditValue = (int) $dit;
            $severity = $this->options->getSeverity($ditValue);

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
                        'DIT (Depth of Inheritance) is %d, exceeds threshold of %d. Prefer composition over deep inheritance',
                        $ditValue,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: $ditValue,
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
