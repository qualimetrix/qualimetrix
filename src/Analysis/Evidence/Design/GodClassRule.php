<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that detects God Classes using Lanza & Marinescu criteria.
 *
 * A God Class is overly complex, large, and lacks cohesion.
 * Detection is based on 4 criteria: WMC, LCOM4, TCC, and class LOC.
 * A class is flagged when it matches minCriteria of the evaluable criteria.
 */
#[CliAlias('god-class-wmc-threshold', 'wmcThreshold')]
#[CliAlias('god-class-lcom-threshold', 'lcomThreshold')]
#[CliAlias('god-class-tcc-threshold', 'tccThreshold')]
#[CliAlias('god-class-class-loc-threshold', 'classLocThreshold')]
#[CliAlias('god-class-min-criteria', 'minCriteria')]
#[CliAlias('god-class-min-methods', 'minMethods')]
#[CliAlias('god-class-exclude-readonly', 'excludeReadonly')]
final class GodClassRule extends AbstractRule
{
    public const string NAME = 'design.god-class';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects God Classes (overly complex, large, low cohesion)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Design;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [
            MetricName::STRUCTURE_WMC,
            MetricName::STRUCTURE_LCOM,
            MetricName::COHESION_TCC,
            MetricName::SIZE_CLASS_LOC,
            MetricName::STRUCTURE_METHOD_COUNT,
            MetricName::STRUCTURE_IS_READONLY,
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof GodClassOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $violation = $this->evaluateClass($context, $classInfo);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function evaluateClass(AnalysisContext $context, SymbolInfo $classInfo): ?Violation
    {
        $subject = $classInfo->subject;
        if ($subject === null || $subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return null;
        }
        $metrics = $context->metrics->get($subject->toSymbolPath());

        // Apply @qmx-threshold overrides for this class
        $effectiveOptions = $this->getEffectiveOptions(
            $context,
            $this->options,
            $subject,
        );
        \assert($effectiveOptions instanceof GodClassOptions);

        if ($this->isExcluded($effectiveOptions, $metrics)) {
            return null;
        }

        $results = GodClassCriteriaEvaluator::evaluate($metrics, $effectiveOptions);
        $evaluableCount = \count($results);

        // Not enough evaluable criteria
        if ($evaluableCount < $effectiveOptions->minCriteria) {
            return null;
        }

        $matched = array_values(array_filter(
            $results,
            static fn(GodClassCriterionResult $result): bool => $result->matched,
        ));
        $matchedCount = \count($matched);

        $severity = $this->determineSeverity($matchedCount, $evaluableCount, $effectiveOptions);
        if ($severity === null) {
            return null;
        }

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'God Class detected (%d/%d criteria): %s',
                $matchedCount,
                $evaluableCount,
                implode(', ', array_map(
                    static fn(GodClassCriterionResult $result): string => $result->message,
                    $matched,
                )),
            ),
            severity: $severity,
            metricValue: $matchedCount,
            recommendation: 'Apply the Single Responsibility Principle. Extract cohesive method groups into separate classes.',
        );
    }

    /**
     * Skips readonly classes (if configured) and classes with too few methods
     * to be meaningfully assessed for God Class criteria.
     */
    private function isExcluded(GodClassOptions $options, MetricBag $metrics): bool
    {
        if ($options->excludeReadonly && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1) {
            return true;
        }

        $methodCount = (int) ($metrics->get(MetricName::STRUCTURE_METHOD_COUNT) ?? 0);

        return $methodCount < $options->minMethods;
    }

    /**
     * Error when every evaluable criterion matched, Warning when at least
     * minCriteria matched, null (no violation) otherwise.
     */
    private function determineSeverity(int $matchedCount, int $evaluableCount, GodClassOptions $options): ?Severity
    {
        if ($matchedCount === $evaluableCount) {
            return Severity::Error;
        }

        if ($matchedCount >= $options->minCriteria) {
            return Severity::Warning;
        }

        return null;
    }

    /**
     * @return class-string<GodClassOptions>
     */
    public static function getOptionsClass(): string
    {
        return GodClassOptions::class;
    }

    /**
     * `design.god-class` reports `$matchedCount` — the tally of how many of
     * the (up to 4) evaluable God Class criteria matched — as `metricValue`
     * (see the emission above), not any individual criterion's value. Higher
     * is worse: {@see determineSeverity()} returns `Severity::Error` when
     * `$matchedCount === $evaluableCount` (line 165, all evaluable criteria
     * matched) and `Severity::Warning` when `$matchedCount >=
     * $options->minCriteria` (line 169) — both branches escalate as the
     * count grows.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
        ];
    }
}
