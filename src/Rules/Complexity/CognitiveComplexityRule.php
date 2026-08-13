<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Complexity;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\Attribute\CliAlias;
use Qualimetrix\Core\Rule\HierarchicalRuleInterface;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Rules\AbstractRule;

/**
 * Hierarchical rule that checks cognitive complexity at method and class levels.
 *
 * - Method level: checks individual method cognitive complexity
 * - Class level: checks maximum cognitive complexity among class methods
 */
#[CliAlias('cognitive-warning', 'method.warning')]
#[CliAlias('cognitive-error', 'method.error')]
#[CliAlias('cognitive-class-warning', 'class.max_warning')]
#[CliAlias('cognitive-class-error', 'class.max_error')]
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
        return [RuleLevel::Callable, RuleLevel::Class_];
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
            RuleLevel::Callable => $this->analyzeMethodLevel($context),
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
            (new ViolationChannel(self::NAME, self::NAME . '.callable'))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
            (new ViolationChannel(self::NAME, self::NAME . '.class'))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
        ];
    }

    /**
     * @return list<Violation>
     */
    private function analyzeMethodLevel(AnalysisContext $context): array
    {
        \assert($this->options instanceof CognitiveComplexityOptions);
        $methodOptions = $this->options->callable;

        $violations = [];

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

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    subject: $subject,
                    symbolPath: $subject->toSymbolPath(),
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.callable',
                    message: \sprintf('Cognitive complexity is %d, exceeds threshold of %d.%s Reduce nesting and break into smaller methods', $cognitiveValue, $threshold, $breakdown !== '' ? " {$breakdown}." : ''),
                    severity: $severity,
                    metricValue: $cognitiveValue,
                    level: RuleLevel::Callable,
                    recommendation: \sprintf('Cognitive complexity: %d (threshold: %d)%s — deeply nested, hard to follow', $cognitiveValue, $threshold, $breakdown !== '' ? ". {$breakdown}" : ''),
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
            $violation = $this->classViolation($classInfo, $subject, $maxCognitiveValue, $effectiveClassOptions);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function classViolation(
        SymbolInfo $classInfo,
        MetricSubject $subject,
        int $maximum,
        ClassCognitiveComplexityOptions $options,
    ): ?Violation {
        $severity = $options->getSeverity($maximum);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $options->maxError : $options->maxWarning;

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME . '.class',
            message: \sprintf('Maximum method cognitive complexity is %d, exceeds threshold of %d. Refactor the most complex methods', $maximum, $threshold),
            severity: $severity,
            metricValue: $maximum,
            level: RuleLevel::Class_,
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
}
