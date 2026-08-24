<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Hierarchical rule that checks cognitive complexity at method and class levels.
 *
 * - Method level: checks individual method cognitive complexity
 * - Class level: checks maximum cognitive complexity among class methods
 *
 * @qmx-threshold coupling.cbo 21 -- Raw CBO 20, from declaring its shape (ADR 0031, the ChannelShape-typed SHAPE constant) alongside the rest of this rule's own dependencies; 21 gets one-edge headroom.
 */
#[CliAlias('cognitive-warning', 'callable.warning')]
#[CliAlias('cognitive-error', 'callable.error')]
#[CliAlias('cognitive-class-warning', 'class.max_warning')]
#[CliAlias('cognitive-class-error', 'class.max_error')]
final class CognitiveComplexityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'complexity.cognitive';
    public const string DOCS_PAGE = 'rules/complexity.md';

    public const int REMEDIATION_MINUTES = 30;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
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
     * @return list<SymbolLevel>
     */
    public function getSupportedLevels(): array
    {
        return [SymbolLevel::Callable, SymbolLevel::Class_];
    }

    /**
     * Analyzes at a specific level.
     *
     * @return list<Finding>
     */
    public function analyzeLevel(SymbolLevel $level, AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);

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
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);

        $findings = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options->isLevelEnabled($level)) {
                $findings = [...$findings, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $findings;
    }

    /**
     * @return class-string<CognitiveComplexityOptions>
     */
    public static function getOptionsClass(): string
    {
        return CognitiveComplexityOptions::class;
    }

    /**
     * Both cognitive-complexity channels report the metric they check as
     * `metricValue` (`$cognitiveValue` in {@see analyzeMethodLevel()},
     * `$maxCognitiveValue` in {@see analyzeClassLevel()}), judged worse the
     * higher it goes:
     * {@see MethodCognitiveComplexityOptions::getSeverity()}'s `$value >=
     * $this->error` (line 48) / `$value >= $this->warning` (line 52) for the
     * method channel, and
     * {@see ClassCognitiveComplexityOptions::getSeverity()}'s `$value >=
     * $this->maxError` (line 50) / `$value >= $this->maxWarning` (line 54)
     * for the class channel.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            FindingChannel::leveled(self::NAME, SymbolLevel::Callable)->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Callable),
            FindingChannel::leveled(self::NAME, SymbolLevel::Class_)->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function analyzeMethodLevel(AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);
        $methodOptions = $this->options->callable;

        $findings = [];

        foreach ($context->metrics->allCallables() as $methodInfo) {
            $subject = $methodInfo->subject ?? throw new LogicException('Cognitive complexity findings require an exact callable subject');
            $metrics = $context->metrics->getSubject($subject);
            $cognitive = $metrics->get(MetricName::COMPLEXITY_COGNITIVE);

            if ($cognitive === null) {
                continue;
            }

            $cognitiveValue = (int) $cognitive;

            /** @var MethodCognitiveComplexityOptions $effectiveMethodOptions */
            $effectiveMethodOptions = $this->getEffectiveOptions($context, $methodOptions, $subject);
            $severity = $effectiveMethodOptions->getSeverity($cognitiveValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $effectiveMethodOptions->error : $effectiveMethodOptions->warning;
                $breakdown = $this->formatBreakdown($metrics->entries('cognitive-complexity.increments'));

                $findings[] = new Finding(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    subject: $subject,
                    symbolPath: $subject->toSymbolPath(),
                    ruleName: $this->getName(),
                    code: FindingChannel::leveled(self::NAME, SymbolLevel::Callable)->code,
                    message: \sprintf('Cognitive complexity is %d, exceeds threshold of %d.%s Reduce nesting and break into smaller methods', $cognitiveValue, $threshold, $breakdown !== '' ? " {$breakdown}." : ''),
                    severity: $severity,
                    metricValue: $cognitiveValue,
                    recommendation: \sprintf('Cognitive complexity: %d (threshold: %d)%s — deeply nested, hard to follow', $cognitiveValue, $threshold, $breakdown !== '' ? ". {$breakdown}" : ''),
                    threshold: $threshold,
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);
        $classOptions = $this->options->class;

        $findings = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Cognitive complexity class findings require an exact declaration subject');
            if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
                continue;
            }
            $metrics = $context->metrics->get($subject->toSymbolPath());
            $maxCognitive = $metrics->get(MetricName::agg(MetricName::COMPLEXITY_COGNITIVE, AggregationStrategy::Max));

            if ($maxCognitive === null) {
                continue;
            }

            $maxCognitiveValue = (int) $maxCognitive;

            /** @var ClassCognitiveComplexityOptions $effectiveClassOptions */
            $effectiveClassOptions = $this->getEffectiveOptions($context, $classOptions, $subject);
            $finding = $this->classFinding($classInfo, $subject, $maxCognitiveValue, $effectiveClassOptions);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function classFinding(
        SymbolInfo $classInfo,
        MetricSubject $subject,
        int $maximum,
        ClassCognitiveComplexityOptions $options,
    ): ?Finding {
        $severity = $options->getSeverity($maximum);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $options->maxError : $options->maxWarning;

        return new Finding(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: FindingChannel::leveled(self::NAME, SymbolLevel::Class_)->code,
            message: \sprintf('Maximum method cognitive complexity is %d, exceeds threshold of %d. Refactor the most complex methods', $maximum, $threshold),
            severity: $severity,
            metricValue: $maximum,
            recommendation: \sprintf('Max cognitive complexity: %d (threshold: %d) — deeply nested, hard to follow', $maximum, $threshold),
            threshold: $threshold,
        );
    }

    /**
     * Formats a compact breakdown of top complexity contributors.
     *
     * Returns empty string if no increment data is available.
     * Example: "Top: nested if +5 L12, foreach +4 L15, &&/|| +1 L22"
     *
     * @param list<array<string, bool|float|int|string>> $entries
     */
    private function formatBreakdown(array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        // Sort by points descending, take top 3
        usort($entries, static fn(array $a, array $b): int => $b['points'] <=> $a['points']);
        $top = \array_slice($entries, 0, 3);

        $parts = [];

        foreach ($top as $entry) {
            $type = (string) $entry['type'];
            $points = (int) $entry['points'];
            $line = (int) $entry['line'];

            $label = $this->formatIncrementLabel($type, $points);
            $parts[] = \sprintf('%s +%d L%d', $label, $points, $line);
        }

        return 'Top: ' . implode(', ', $parts);
    }

    /**
     * Returns a human-readable label for a complexity increment.
     *
     * Structures with nesting bonus (points > 1) get a "nested" prefix.
     */
    private function formatIncrementLabel(string $type, int $points): string
    {
        // These structure types receive nesting bonus (1 + nestingLevel)
        $nestingTypes = ['if', 'for', 'foreach', 'while', 'do', 'catch', 'switch', 'match'];

        if ($points > 1 && \in_array($type, $nestingTypes, true)) {
            return 'nested ' . $type;
        }

        return $type;
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
