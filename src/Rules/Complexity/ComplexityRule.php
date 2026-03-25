<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Complexity;

use Qualimetrix\Core\Metric\MetricBag;
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
 * Hierarchical rule that checks complexity at method and class levels.
 *
 * - Method level: checks individual method CCN
 * - Class level: checks maximum CCN among class methods
 */
final class ComplexityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'complexity.cyclomatic';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks cyclomatic complexity at method and class levels';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Complexity;
    }

    /**
     * Default cognitive complexity warning threshold.
     *
     * Used to detect divergence: high CCN with low cognitive complexity
     * suggests mechanical branching (switch/match) rather than truly complex logic.
     */
    private const int COGNITIVE_WARNING_THRESHOLD = 15;

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::COMPLEXITY_CCN, MetricName::COMPLEXITY_COGNITIVE];
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
        \assert($this->options instanceof ComplexityOptions);

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
        \assert($this->options instanceof ComplexityOptions);

        $violations = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options->isLevelEnabled($level)) {
                $violations = [...$violations, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $violations;
    }

    /**
     * @return class-string<ComplexityOptions>
     */
    public static function getOptionsClass(): string
    {
        return ComplexityOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'cyclomatic-warning' => 'method.warning',
            'cyclomatic-error' => 'method.error',
            'cyclomatic-class-warning' => 'class.max_warning',
            'cyclomatic-class-error' => 'class.max_error',
        ];
    }

    /**
     * @return list<Violation>
     */
    private function analyzeMethodLevel(AnalysisContext $context): array
    {
        \assert($this->options instanceof ComplexityOptions);
        $methodOptions = $this->options->method;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Method) as $methodInfo) {
            $metrics = $context->metrics->get($methodInfo->symbolPath);
            $ccn = $metrics->get(MetricName::COMPLEXITY_CCN);

            if ($ccn === null) {
                continue;
            }

            $ccnValue = (int) $ccn;
            $severity = $methodOptions->getSeverity($ccnValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $methodOptions->error : $methodOptions->warning;
                $recommendation = $this->buildMethodRecommendation($ccnValue, $threshold, $metrics);

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    symbolPath: $methodInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.method',
                    message: \sprintf('Cyclomatic complexity is %d, exceeds threshold of %d. Consider extracting methods or simplifying conditions', $ccnValue, $threshold),
                    severity: $severity,
                    metricValue: $ccnValue,
                    level: RuleLevel::Method,
                    recommendation: $recommendation,
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * Builds recommendation text for method-level CCN violations.
     *
     * When CCN is high but cognitive complexity is low, this indicates
     * mechanical branching (e.g., switch/match statements) rather than
     * genuinely complex logic — a lower refactoring priority.
     */
    private function buildMethodRecommendation(int $ccnValue, int $threshold, MetricBag $metrics): string
    {
        $cognitive = $metrics->get(MetricName::COMPLEXITY_COGNITIVE);

        if ($cognitive !== null && (int) $cognitive < self::COGNITIVE_WARNING_THRESHOLD) {
            return \sprintf(
                'Cyclomatic complexity: %d (threshold: %d) — high CCN with low cognitive complexity (%d) suggests mechanical branching (switch/match). Lower refactoring priority.',
                $ccnValue,
                $threshold,
                (int) $cognitive,
            );
        }

        return \sprintf('Cyclomatic complexity: %d (threshold: %d) — too many code paths', $ccnValue, $threshold);
    }

    /**
     * @return list<Violation>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        \assert($this->options instanceof ComplexityOptions);
        $classOptions = $this->options->class;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $maxCcn = $metrics->get(MetricName::COMPLEXITY_CCN . '.max');

            if ($maxCcn === null) {
                continue;
            }

            $maxCcnValue = (int) $maxCcn;
            $severity = $classOptions->getSeverity($maxCcnValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $classOptions->maxError : $classOptions->maxWarning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.class',
                    message: \sprintf('Maximum method cyclomatic complexity is %d, exceeds threshold of %d. Refactor the most complex methods', $maxCcnValue, $threshold),
                    severity: $severity,
                    metricValue: $maxCcnValue,
                    level: RuleLevel::Class_,
                    recommendation: \sprintf('Max cyclomatic complexity: %d (threshold: %d) — too many code paths', $maxCcnValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }
}
