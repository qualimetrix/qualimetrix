<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Architecture;

use Qualimetrix\Core\Dependency\CycleInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

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
            $severity = $this->getEffectiveSeverity($context, $this->options, '', 1, $cycle->getSize());
            if ($severity === null) {
                continue; // Cycle too large or filtered out
            }

            // Use the first class in the cycle as the violation location
            $classes = $cycle->getClasses();
            $firstClass = $classes[0] ?? null;
            if ($firstClass === null) {
                continue; // Empty cycle
            }

            $category = $cycle->getSizeCategory();
            $size = $cycle->getSize();

            // Truncate path display for large cycles
            $pathDisplay = $category === 'large'
                ? $cycle->toTruncatedShortString(5)
                : $cycle->toShortString();

            $message = \sprintf(
                'Circular dependency (%d classes): %s',
                $size,
                $pathDisplay,
            );

            $recommendation = $this->buildRecommendation($cycle, $category);

            $violations[] = new Violation(
                location: Location::none(),
                symbolPath: $firstClass,
                ruleName: $this->getName(),
                violationCode: self::NAME,
                message: $message,
                severity: $severity,
                metricValue: $size,
                recommendation: $recommendation,
            );
        }

        return $violations;
    }

    /**
     * Builds an actionable recommendation based on cycle size category.
     *
     * For small/medium cycles, provides specific guidance.
     * For large cycles, emphasizes that the cycle is too large to fix at once
     * and suggests focusing on entry-point classes.
     *
     * Includes structured cycle data (JSON) for AI agent consumption.
     *
     * @param 'small'|'medium'|'large' $category
     */
    private function buildRecommendation(
        CycleInterface $cycle,
        string $category,
    ): string {
        $structuredData = $cycle->toStructuredData();
        $jsonData = json_encode($structuredData, \JSON_UNESCAPED_SLASHES);

        $guidance = match ($category) {
            'small' => \sprintf(
                'Cycle path: %s (%d classes). Break by introducing an interface to invert one dependency.',
                $cycle->toShortString(),
                $cycle->getSize(),
            ),
            'medium' => \sprintf(
                'Cycle path: %s (%d classes). Consider extracting a shared abstraction layer or splitting into smaller modules.',
                $cycle->toShortString(),
                $cycle->getSize(),
            ),
            'large' => \sprintf(
                'Large cycle (%d classes) — focus on the entry-point classes: %s. '
                . 'Break the cycle incrementally by introducing interfaces at key boundaries.',
                $cycle->getSize(),
                $cycle->toTruncatedShortString(3),
            ),
        };

        return $guidance . "\n" . 'Cycle data: ' . $jsonData;
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
