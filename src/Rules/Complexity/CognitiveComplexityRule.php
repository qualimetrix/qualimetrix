<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Complexity;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\HierarchicalRuleInterface;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Hierarchical rule that checks cognitive complexity at method and class levels.
 *
 * - Method level: checks individual method cognitive complexity
 * - Class level: checks maximum cognitive complexity among class methods
 */
final class CognitiveComplexityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'complexity.cognitive';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks cognitive complexity at method and class levels';
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
        return [MetricName::COMPLEXITY_COGNITIVE];
    }

    /**
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array
    {
        return [RuleLevel::Method, RuleLevel::Class_];
    }

    /**
     * Analyzes at a specific level.
     *
     * @return list<Violation>
     */
    public function analyzeLevel(RuleLevel $level, AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);

        $levelOptions = $this->options->forLevel($level);
        if (!$levelOptions->isEnabled()) {
            return [];
        }

        return match ($level) {
            RuleLevel::Method => $this->analyzeMethodLevel($context),
            RuleLevel::Class_ => $this->analyzeClassLevel($context),
            default => [],
        };
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);

        $violations = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options->isLevelEnabled($level)) {
                $violations = [...$violations, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $violations;
    }

    /**
     * @return class-string<CognitiveComplexityOptions>
     */
    public static function getOptionsClass(): string
    {
        return CognitiveComplexityOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            // Method-level aliases
            'cognitive-warning' => 'method.warning',
            'cognitive-error' => 'method.error',
            // Class-level aliases
            'cognitive-class-warning' => 'class.max_warning',
            'cognitive-class-error' => 'class.max_error',
        ];
    }

    /**
     * @return list<Violation>
     */
    private function analyzeMethodLevel(AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);
        $methodOptions = $this->options->method;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Method) as $methodInfo) {
            $metrics = $context->metrics->get($methodInfo->symbolPath);
            $cognitive = $metrics->get(MetricName::COMPLEXITY_COGNITIVE);

            if ($cognitive === null) {
                continue;
            }

            $cognitiveValue = (int) $cognitive;

            /** @var MethodCognitiveComplexityOptions $effectiveMethodOptions */
            $effectiveMethodOptions = $this->getEffectiveOptions($context, $methodOptions, $methodInfo->file, $methodInfo->line ?? 1);
            $severity = $effectiveMethodOptions->getSeverity($cognitiveValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $effectiveMethodOptions->error : $effectiveMethodOptions->warning;

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    symbolPath: $methodInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.method',
                    message: \sprintf('Cognitive complexity is %d, exceeds threshold of %d. Reduce nesting and break into smaller methods', $cognitiveValue, $threshold),
                    severity: $severity,
                    metricValue: $cognitiveValue,
                    level: RuleLevel::Method,
                    recommendation: \sprintf('Cognitive complexity: %d (threshold: %d) — deeply nested, hard to follow', $cognitiveValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);
        $classOptions = $this->options->class;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $maxCognitive = $metrics->get(MetricName::COMPLEXITY_COGNITIVE . '.max');

            if ($maxCognitive === null) {
                continue;
            }

            $maxCognitiveValue = (int) $maxCognitive;

            /** @var ClassCognitiveComplexityOptions $effectiveClassOptions */
            $effectiveClassOptions = $this->getEffectiveOptions($context, $classOptions, $classInfo->file, $classInfo->line ?? 1);
            $severity = $effectiveClassOptions->getSeverity($maxCognitiveValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $effectiveClassOptions->maxError : $effectiveClassOptions->maxWarning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.class',
                    message: \sprintf('Maximum method cognitive complexity is %d, exceeds threshold of %d. Refactor the most complex methods', $maxCognitiveValue, $threshold),
                    severity: $severity,
                    metricValue: $maxCognitiveValue,
                    level: RuleLevel::Class_,
                    recommendation: \sprintf('Max cognitive complexity: %d (threshold: %d) — deeply nested, hard to follow', $maxCognitiveValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }
}
