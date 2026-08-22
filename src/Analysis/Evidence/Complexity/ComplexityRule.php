<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Hierarchical rule that checks complexity at method and class levels.
 *
 * - Method level: checks individual method CCN
 * - Class level: checks maximum CCN among class methods
 */
#[CliAlias('cyclomatic-warning', 'callable.warning')]
#[CliAlias('cyclomatic-error', 'callable.error')]
#[CliAlias('cyclomatic-class-warning', 'class.max_warning')]
#[CliAlias('cyclomatic-class-error', 'class.max_error')]
final class ComplexityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'complexity.cyclomatic';
    public const string DOCS_PAGE = 'rules/complexity.md';

    public const int REMEDIATION_MINUTES = 30;
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
        return [RuleLevel::Callable, RuleLevel::Class_];
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
     * `complexity.cyclomatic.callable` reports the method's raw CCN
     * (`$ccnValue`, an `int`) as `metricValue` — see the emission at
     * {@see analyzeMethodLevel()} — and is judged worse the higher it goes,
     * per {@see MethodComplexityOptions::getSeverity()}'s `$value >=
     * $this->error` (line 53) / `$value >= $this->warning` (line 57)
     * comparisons. `complexity.cyclomatic.class` reports the class's maximum
     * method CCN (`$maxCcnValue` — see {@see analyzeClassLevel()}), also
     * higher-is-worse, per {@see ClassComplexityOptions::getSeverity()}'s
     * `$value >= $this->maxError` (line 55) / `$value >= $this->maxWarning`
     * (line 59).
     *
     * Keyed by the full channel key: the `ruleName` half is `self::NAME`,
     * the `violationCode` half adds the `.callable`/`.class` suffix.
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
        \assert($this->options instanceof ComplexityOptions);
        $methodOptions = $this->options->callable;

        $violations = [];

        foreach ($context->metrics->allCallables() as $methodInfo) {
            $subject = $methodInfo->subject ?? throw new LogicException('Cyclomatic complexity findings require an exact callable subject');
            $metrics = $context->metrics->getSubject($subject);
            $ccn = $metrics->get(MetricName::COMPLEXITY_CCN);
            $cognitive = $metrics->get(MetricName::COMPLEXITY_COGNITIVE);

            if ($ccn === null) {
                continue;
            }

            $ccnValue = (int) $ccn;

            /** @var MethodComplexityOptions $effectiveMethodOptions */
            $effectiveMethodOptions = $this->getEffectiveOptions($context, $methodOptions, $subject);
            $severity = $effectiveMethodOptions->getSeverity($ccnValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $effectiveMethodOptions->error : $effectiveMethodOptions->warning;
                $recommendation = $this->buildMethodRecommendation(
                    $ccnValue,
                    $threshold,
                    $cognitive === null ? null : (int) $cognitive,
                );

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    subject: $subject,
                    symbolPath: $subject->toSymbolPath(),
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.callable',
                    message: \sprintf('Cyclomatic complexity is %d, exceeds threshold of %d. Consider extracting methods or simplifying conditions', $ccnValue, $threshold),
                    severity: $severity,
                    metricValue: $ccnValue,
                    level: RuleLevel::Callable,
                    recommendation: $recommendation,
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * Builds recommendation text for callable-level CCN violations.
     *
     * When CCN is high but cognitive complexity is low, this indicates
     * mechanical branching (e.g., switch/match statements) rather than
     * genuinely complex logic — a lower refactoring priority.
     */
    private function buildMethodRecommendation(int $ccnValue, int $threshold, ?int $cognitive): string
    {
        if ($cognitive !== null && $cognitive < self::COGNITIVE_WARNING_THRESHOLD) {
            return \sprintf(
                'Cyclomatic complexity: %d (threshold: %d) — high CCN with low cognitive complexity (%d) suggests mechanical branching (switch/match). Lower refactoring priority.',
                $ccnValue,
                $threshold,
                $cognitive,
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

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Cyclomatic complexity class findings require an exact declaration subject');
            if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
                continue;
            }
            $metrics = $context->metrics->get($subject->toSymbolPath());
            $maxCcn = $metrics->get(MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Max));

            if ($maxCcn === null) {
                continue;
            }

            $maxCcnValue = (int) $maxCcn;

            /** @var ClassComplexityOptions $effectiveClassOptions */
            $effectiveClassOptions = $this->getEffectiveOptions($context, $classOptions, $subject);
            $violation = $this->classViolation($classInfo, $subject, $maxCcnValue, $effectiveClassOptions);
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
        ClassComplexityOptions $options,
    ): ?Violation {
        /** @var array{Severity, int}|null $projection */
        $projection = match (true) {
            $maximum >= $options->maxError => [Severity::Error, $options->maxError],
            $maximum >= $options->maxWarning => [Severity::Warning, $options->maxWarning],
            default => null,
        };

        if ($projection === null) {
            return null;
        }
        [$severity, $threshold] = $projection;

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME . '.class',
            message: \sprintf('Maximum method cyclomatic complexity is %d, exceeds threshold of %d. Refactor the most complex methods', $maximum, $threshold),
            severity: $severity,
            metricValue: $maximum,
            level: RuleLevel::Class_,
            recommendation: \sprintf('Max cyclomatic complexity: %d (threshold: %d) — too many code paths', $maximum, $threshold),
            threshold: $threshold,
        );
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
