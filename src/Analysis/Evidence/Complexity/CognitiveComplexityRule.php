<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;

use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Hierarchical rule that checks cognitive complexity at callable and class levels.
 *
 * - Callable level: checks the cognitive complexity of one method or global function
 * - Class level: checks maximum cognitive complexity among class methods
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

    public static function getDescription(): string
    {
        return 'Checks cognitive complexity at method and class levels';
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
     * Both levels of the channel report the metric they check as
     * `metricValue` (`$cognitiveValue` in {@see analyzeMethodLevel()},
     * `$maxCognitiveValue` in {@see analyzeClassLevel()}), judged worse the
     * higher it goes:
     * {@see MethodCognitiveComplexityOptions::getSeverity()}'s `$value >=
     * $this->error` / `$value >= $this->warning` at the
     * callable level, and
     * {@see ClassCognitiveComplexityOptions::getSeverity()}'s `$value >=
     * $this->maxError` / `$value >= $this->maxWarning`
     * at the class level.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => self::judgingHigher(
                [
                    MetricName::COMPLEXITY_COGNITIVE,
                    MetricName::agg(MetricName::COMPLEXITY_COGNITIVE, AggregationStrategy::Max),
                ],
                SymbolLevel::Callable,
                SymbolLevel::Class_,
            )->withGates(
                self::populationGate('callable-value', self::NAME, SymbolLevel::Callable, 'callable', self::keyPresent('callable-value', [MetricName::COMPLEXITY_COGNITIVE]), 'Callable complexity was not published.'),
                self::populationGate('class-coordinate', self::NAME, SymbolLevel::Class_, 'declaration', self::kindIn('class-coordinate', [SymbolType::Class_]), 'The subject is outside the class coordinate.'),
                self::populationGate('class-maximum', self::NAME, SymbolLevel::Class_, 'declaration', self::keyPresent('class-maximum', [MetricName::agg(MetricName::COMPLEXITY_COGNITIVE, AggregationStrategy::Max)]), 'Maximum method complexity was not published.'),
            ),
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

        foreach ($this->admittedDeclarations($context, self::channelDeclarations()[self::NAME], $context->metrics->allCallables(), SymbolLevel::Callable, 'callable-value', unit: 'callable') as [$methodInfo, $subject, $metrics]) {
            $cognitive = $metrics->get(MetricName::COMPLEXITY_COGNITIVE);

            $cognitiveValue = (int) $cognitive;

            /** @var MethodCognitiveComplexityOptions $effectiveMethodOptions */
            $effectiveMethodOptions = $this->getEffectiveOptions($context, $methodOptions, $subject);
            $severity = $effectiveMethodOptions->getSeverity($cognitiveValue);

            if ($severity !== null) {
                $findings[] = $this->callableFinding(
                    new Location($methodInfo->file, $methodInfo->line),
                    $subject,
                    $this->formatBreakdown($metrics->entries('cognitive-complexity.increments')),
                    $cognitiveValue,
                    $severity,
                    $severity === Severity::Error ? $effectiveMethodOptions->error : $effectiveMethodOptions->warning,
                );
            }
        }

        return $findings;
    }

    private function callableFinding(
        Location $location,
        MetricSubject $subject,
        string $breakdown,
        int $cognitiveValue,
        Severity $severity,
        int $threshold,
    ): Finding {

        return new Finding(
            location: $location,
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf('Cognitive complexity is %d, ' . ThresholdCrossing::of($cognitiveValue, $threshold)->value . ' threshold of %d.%s Reduce nesting and break into smaller methods', $cognitiveValue, $threshold, $breakdown !== '' ? " {$breakdown}." : ''),
            severity: $severity,
            metricValue: $cognitiveValue,
            recommendation: \sprintf('Cognitive complexity: %d (threshold: %d)%s — deeply nested, hard to follow', $cognitiveValue, $threshold, $breakdown !== '' ? ". {$breakdown}" : ''),
            threshold: $threshold,
        );
    }

    /**
     * @return list<Finding>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);
        $classOptions = $this->options->class;

        $findings = [];

        foreach ($this->admittedDeclarations($context, self::channelDeclarations()[self::NAME], $context->metrics->allClassDeclarations(), SymbolLevel::Class_, 'class-maximum', 'class-coordinate') as [$classInfo, $subject, $metrics]) {
            $maxCognitive = $metrics->get(MetricName::agg(MetricName::COMPLEXITY_COGNITIVE, AggregationStrategy::Max));

            $maxCognitiveValue = (int) $maxCognitive;

            /** @var ClassCognitiveComplexityOptions $effectiveClassOptions */
            $effectiveClassOptions = $this->getEffectiveOptions($context, $classOptions, $subject);
            $finding = $this->classFinding(new Location($classInfo->file, $classInfo->line), $subject, $maxCognitiveValue, $effectiveClassOptions);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function classFinding(
        Location $location,
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
            location: $location,
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf('Maximum method cognitive complexity is %d, ' . ThresholdCrossing::of($maximum, $threshold)->value . ' threshold of %d. Refactor the most complex methods', $maximum, $threshold),
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
        $nestingTypes = ['if', 'for', 'foreach', 'while', 'do', 'catch', 'switch', 'match', 'ternary'];

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
