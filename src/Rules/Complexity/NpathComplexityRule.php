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
    private const int MAX_DISPLAY = 1_000_000;

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
        return [MetricName::COMPLEXITY_NPATH];
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
        \assert($this->options instanceof NpathComplexityOptions);

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
        \assert($this->options instanceof NpathComplexityOptions);

        $violations = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options->isLevelEnabled($level)) {
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
     * Returns a human-readable severity category for the given NPath value.
     *
     * Categories are based on absolute NPath values, independent of configured thresholds.
     */
    private function getCategoryLabel(int $npath): string
    {
        return match (true) {
            $npath > 1_000_000 => 'extreme',
            $npath > 10_000 => 'very high',
            $npath > 1_000 => 'high',
            default => 'moderate',
        };
    }

    /**
     * @return list<Violation>
     */
    private function analyzeMethodLevel(AnalysisContext $context): array
    {
        \assert($this->options instanceof NpathComplexityOptions);
        $methodOptions = $this->options->method;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Method) as $methodInfo) {
            $metrics = $context->metrics->get($methodInfo->symbolPath);
            $npath = $metrics->get(MetricName::COMPLEXITY_NPATH);

            if ($npath === null) {
                continue;
            }

            $npathValue = (int) $npath;

            /** @var MethodNpathComplexityOptions $effectiveMethodOptions */
            $effectiveMethodOptions = $this->getEffectiveOptions($context, $methodOptions, $methodInfo->file, $methodInfo->line ?? 1);
            $severity = $effectiveMethodOptions->getSeverity($npathValue);

            if ($severity !== null) {
                $displayValue = $npathValue >= self::MAX_DISPLAY ? '> 1M' : (string) $npathValue;
                $categoryLabel = $this->getCategoryLabel($npathValue);
                $threshold = $severity === Severity::Error ? $effectiveMethodOptions->error : $effectiveMethodOptions->warning;
                $chain = $this->formatChain($metrics);

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    symbolPath: $methodInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.method',
                    message: \sprintf('NPath complexity (execution paths) is %s (%s), exceeds threshold of %s.%s Reduce branching or extract methods', $displayValue, $categoryLabel, $threshold, $chain !== '' ? " {$chain}." : ''),
                    severity: $severity,
                    metricValue: $npathValue,
                    level: RuleLevel::Method,
                    recommendation: \sprintf('NPath complexity: %s (threshold: %s)%s — explosive number of execution paths', $displayValue, $threshold, $chain !== '' ? ". {$chain}" : ''),
                    threshold: (float) $threshold,
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
        \assert($this->options instanceof NpathComplexityOptions);
        $classOptions = $this->options->class;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $maxNpath = $metrics->get(MetricName::COMPLEXITY_NPATH . '.max');

            if ($maxNpath === null) {
                continue;
            }

            $maxNpathValue = (int) $maxNpath;

            /** @var ClassNpathComplexityOptions $effectiveClassOptions */
            $effectiveClassOptions = $this->getEffectiveOptions($context, $classOptions, $classInfo->file, $classInfo->line ?? 1);
            $severity = $effectiveClassOptions->getSeverity($maxNpathValue);

            if ($severity !== null) {
                $displayValue = $maxNpathValue >= self::MAX_DISPLAY ? '> 1M' : (string) $maxNpathValue;
                $categoryLabel = $this->getCategoryLabel($maxNpathValue);
                $threshold = $severity === Severity::Error ? $effectiveClassOptions->maxError : $effectiveClassOptions->maxWarning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.class',
                    message: \sprintf('Maximum method NPath complexity is %s (%s), exceeds threshold of %s. Refactor the most complex methods', $displayValue, $categoryLabel, $threshold),
                    severity: $severity,
                    metricValue: $maxNpathValue,
                    level: RuleLevel::Class_,
                    recommendation: \sprintf('Max NPath complexity: %s (threshold: %s) — explosive number of execution paths', $displayValue, $threshold),
                    threshold: (float) $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * Formats a compact multiplicative chain of top NPath factors.
     *
     * Returns empty string if no factor data is available.
     * Example: "Chain: ×6 if/else L25, ×4 match L31, ×3 switch L20"
     */
    private function formatChain(MetricBag $metrics): string
    {
        $entries = $metrics->entries('npath-complexity.factors');

        if ($entries === []) {
            return '';
        }

        // Sort by factor descending, take top 3
        usort($entries, static fn(array $a, array $b): int => $b['factor'] <=> $a['factor']);
        $top = \array_slice($entries, 0, 3);

        $parts = [];

        foreach ($top as $entry) {
            $type = (string) $entry['type'];
            $factor = (int) $entry['factor'];
            $line = (int) $entry['line'];

            $displayFactor = $factor >= self::MAX_DISPLAY ? '> 1M' : (string) $factor;
            $parts[] = \sprintf('×%s %s L%d', $displayFactor, $type, $line);
        }

        return 'Chain: ' . implode(', ', $parts);
    }
}
