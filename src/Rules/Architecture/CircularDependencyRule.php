<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Architecture;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Detects circular dependencies between classes.
 *
 * Circular dependencies (A depends on B, B depends on C, C depends on A) are
 * architectural anti-patterns that make code harder to test, understand, and maintain.
 *
 * This rule reads cycles from the typed AnalysisContext::$cycles property.
 */
final class CircularDependencyRule extends AbstractRule
{
    public const string NAME = 'architecture.circular-dependency';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects circular dependencies between classes';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Architecture;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return []; // Requires dependency graph, not metrics
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        \assert($this->options instanceof CircularDependencyOptions);

        if ($context->cycles === []) {
            return [];
        }

        $violations = [];

        foreach ($context->cycles as $cycle) {
            $severity = $this->options->getSeverity($cycle->getSize());
            if ($severity === null) {
                continue; // Cycle too large or filtered out
            }

            // Use the first class in the cycle as the violation location
            $classes = $cycle->getClasses();
            $firstClass = $classes[0] ?? null;
            if ($firstClass === null) {
                continue; // Empty cycle
            }

            $violations[] = new Violation(
                location: Location::none(),
                symbolPath: $firstClass,
                ruleName: $this->getName(),
                violationCode: self::NAME,
                message: \sprintf(
                    'Circular dependency (%d classes): %s. Break the cycle by introducing interfaces or restructuring',
                    $cycle->getSize(),
                    $cycle->toShortString(),
                ),
                severity: $severity,
                metricValue: $cycle->getSize(),
            );
        }

        return $violations;
    }

    /**
     * @return class-string<CircularDependencyOptions>
     */
    public static function getOptionsClass(): string
    {
        return CircularDependencyOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'circular-deps' => 'enabled',
            'max-cycle-size' => 'maxCycleSize',
        ];
    }
}
