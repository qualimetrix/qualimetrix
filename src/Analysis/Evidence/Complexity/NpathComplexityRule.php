<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Hierarchical rule that checks NPath complexity at method and class levels.
 *
 * NPath Complexity counts the number of acyclic execution paths through a method.
 * Unlike Cyclomatic Complexity (additive), NPath is multiplicative and grows exponentially.
 *
 * - Method level: checks individual method NPath
 * - Class level: checks maximum NPath among class methods
 */
#[CliAlias('npath-warning', 'callable.warning')]
#[CliAlias('npath-error', 'callable.error')]
#[CliAlias('npath-class-warning', 'class.max_warning')]
#[CliAlias('npath-class-error', 'class.max_error')]
final class NpathComplexityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'complexity.npath';
    public const string DOCS_PAGE = 'rules/complexity.md';

    public const int REMEDIATION_MINUTES = 30;
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
     * @return list<SymbolLevel>
     */
    public function getSupportedLevels(): array
    {
        return [SymbolLevel::Callable, SymbolLevel::Class_];
    }

    /**
     * Analyzes at a specific level.
     *
     * @return list<Violation>
     */
    public function analyzeLevel(SymbolLevel $level, AnalysisContext $context): array
    {
        \assert($this->options instanceof NpathComplexityOptions);

        $levelOptions = $this->options->forLevel($level);
        if (!$levelOptions->isEnabled()) {
            return [];
        }

        return match ($level) {
            SymbolLevel::Callable => $this->analyzeMethodLevel($context),
            SymbolLevel::Class_ => $this->analyzeClassLevel($context),
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
     * Both NPath channels report the metric they check as `metricValue`
     * (`$npathValue` in {@see analyzeMethodLevel()}, `$maxNpathValue` in
     * {@see analyzeClassLevel()}), judged worse the higher it goes:
     * {@see MethodNpathComplexityOptions::getSeverity()}'s `$value >=
     * $this->error` (line 48) / `$value >= $this->warning` (line 52) for the
     * method channel, and
     * {@see ClassNpathComplexityOptions::getSeverity()}'s `$value >=
     * $this->maxError` (line 48) / `$value >= $this->maxWarning` (line 52)
     * for the class channel.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            ViolationChannel::leveled(self::NAME, SymbolLevel::Callable)->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Callable),
            ViolationChannel::leveled(self::NAME, SymbolLevel::Class_)->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
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
        $methodOptions = $this->options->callable;

        $violations = [];

        foreach ($context->metrics->allCallables() as $methodInfo) {
            $subject = $methodInfo->subject ?? throw new LogicException('NPath complexity findings require an exact callable subject');
            $metrics = $context->metrics->getSubject($subject);
            $npath = $metrics->get(MetricName::COMPLEXITY_NPATH);

            if ($npath === null) {
                continue;
            }

            $npathValue = (int) $npath;

            /** @var MethodNpathComplexityOptions $effectiveMethodOptions */
            $effectiveMethodOptions = $this->getEffectiveOptions($context, $methodOptions, $subject);
            $severity = $effectiveMethodOptions->getSeverity($npathValue);

            if ($severity !== null) {
                $displayValue = $npathValue >= self::MAX_DISPLAY ? '> 1M' : (string) $npathValue;
                $categoryLabel = $this->getCategoryLabel($npathValue);
                $threshold = $severity === Severity::Error ? $effectiveMethodOptions->error : $effectiveMethodOptions->warning;
                $chain = $this->formatChain($metrics);

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    subject: $subject,
                    symbolPath: $subject->toSymbolPath(),
                    ruleName: $this->getName(),
                    violationCode: ViolationChannel::leveled(self::NAME, SymbolLevel::Callable)->violationCode,
                    message: \sprintf('NPath complexity (execution paths) is %s (%s), exceeds threshold of %s.%s Reduce branching or extract methods', $displayValue, $categoryLabel, $threshold, $chain !== '' ? " {$chain}." : ''),
                    severity: $severity,
                    metricValue: $npathValue,
                    level: SymbolLevel::Callable,
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

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('NPath complexity class findings require an exact declaration subject');
            if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
                continue;
            }
            $metrics = $context->metrics->get($subject->toSymbolPath());
            $maxNpath = $metrics->get(MetricName::agg(MetricName::COMPLEXITY_NPATH, AggregationStrategy::Max));

            if ($maxNpath === null) {
                continue;
            }

            $maxNpathValue = (int) $maxNpath;

            /** @var ClassNpathComplexityOptions $effectiveClassOptions */
            $effectiveClassOptions = $this->getEffectiveOptions($context, $classOptions, $subject);
            $severity = $effectiveClassOptions->getSeverity($maxNpathValue);

            if ($severity !== null) {
                $displayValue = $maxNpathValue >= self::MAX_DISPLAY ? '> 1M' : (string) $maxNpathValue;
                $categoryLabel = $this->getCategoryLabel($maxNpathValue);
                $threshold = $severity === Severity::Error ? $effectiveClassOptions->maxError : $effectiveClassOptions->maxWarning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    subject: $subject,
                    symbolPath: $subject->toSymbolPath(),
                    ruleName: $this->getName(),
                    violationCode: ViolationChannel::leveled(self::NAME, SymbolLevel::Class_)->violationCode,
                    message: \sprintf('Maximum method NPath complexity is %s (%s), exceeds threshold of %s. Refactor the most complex methods', $displayValue, $categoryLabel, $threshold),
                    severity: $severity,
                    metricValue: $maxNpathValue,
                    level: SymbolLevel::Class_,
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

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
