<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Complexity;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\HierarchicalRuleInterface;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Hierarchical rule that checks NPath complexity at method and class levels.
 *
 * NPath Complexity counts the number of acyclic execution paths through a method.
 * Unlike Cyclomatic Complexity (additive), NPath is multiplicative and grows exponentially.
 *
 * - Method level: checks individual method NPath
 * - Class level: checks maximum NPath among class methods
 */
final class NpathComplexityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'complexity.npath';
    private const string METRIC_NPATH = 'npath';
    private const int MAX_DISPLAY = 1_000_000_000;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks NPath complexity at method and class levels';
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
        return [self::METRIC_NPATH];
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
        if (!$this->options instanceof NpathComplexityOptions) {
            return [];
        }

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
        $violations = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options instanceof NpathComplexityOptions && $this->options->isLevelEnabled($level)) {
                $violations = [...$violations, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $violations;
    }

    /**
     * @return class-string<NpathComplexityOptions>
     */
    public static function getOptionsClass(): string
    {
        return NpathComplexityOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            // Method-level aliases
            'npath-warning' => 'method.warning',
            'npath-error' => 'method.error',
            // Class-level aliases
            'npath-class-warning' => 'class.max_warning',
            'npath-class-error' => 'class.max_error',
        ];
    }

    /**
     * @return list<Violation>
     */
    private function analyzeMethodLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof NpathComplexityOptions) {
            return [];
        }
        $methodOptions = $this->options->method;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Method) as $methodInfo) {
            $metrics = $context->metrics->get($methodInfo->symbolPath);
            $npath = $metrics->get(self::METRIC_NPATH);

            if ($npath === null) {
                continue;
            }

            $npathValue = (int) $npath;
            $severity = $methodOptions->getSeverity($npathValue);

            if ($severity !== null) {
                $displayValue = $npathValue >= self::MAX_DISPLAY ? '> 10^9' : (string) $npathValue;
                $threshold = $severity === Severity::Error ? $methodOptions->error : $methodOptions->warning;

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    symbolPath: $methodInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.method',
                    message: \sprintf('NPath complexity (execution paths) is %s, exceeds threshold of %s. Reduce branching or extract methods', $displayValue, $threshold),
                    severity: $severity,
                    metricValue: $npathValue,
                    level: RuleLevel::Method,
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
        if (!$this->options instanceof NpathComplexityOptions) {
            return [];
        }
        $classOptions = $this->options->class;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $maxNpath = $metrics->get('npath.max');

            if ($maxNpath === null) {
                continue;
            }

            $maxNpathValue = (int) $maxNpath;
            $severity = $classOptions->getSeverity($maxNpathValue);

            if ($severity !== null) {
                $displayValue = $maxNpathValue >= self::MAX_DISPLAY ? '> 10^9' : (string) $maxNpathValue;
                $threshold = $severity === Severity::Error ? $classOptions->maxError : $classOptions->maxWarning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.class',
                    message: \sprintf('Maximum method NPath complexity is %s, exceeds threshold of %s. Refactor the most complex methods', $displayValue, $threshold),
                    severity: $severity,
                    metricValue: $maxNpathValue,
                    level: RuleLevel::Class_,
                );
            }
        }

        return $violations;
    }
}
