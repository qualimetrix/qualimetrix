<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Design;

use LogicException;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\Attribute\CliAlias;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that detects Data Classes — classes with high public surface but low complexity.
 *
 * A Data Class has many public accessors (high WOC) but simple logic (low WMC),
 * suggesting it only holds data without encapsulating behavior.
 * Pure DTOs (readonly, promoted-properties-only, or marked as data class) are excluded.
 */
#[CliAlias('data-class-woc-threshold', 'wocThreshold')]
#[CliAlias('data-class-wmc-threshold', 'wmcThreshold')]
#[CliAlias('data-class-min-methods', 'minMethods')]
#[CliAlias('data-class-exclude-readonly', 'excludeReadonly')]
#[CliAlias('data-class-exclude-promoted-only', 'excludePromotedOnly')]
#[CliAlias('data-class-exclude-exceptions', 'excludeExceptions')]
final class DataClassRule extends AbstractRule
{
    public const string NAME = 'design.data-class';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects classes with high public surface but low complexity (Data Classes)';
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
            MetricName::STRUCTURE_WOC,
            MetricName::STRUCTURE_WMC,
            MetricName::STRUCTURE_METHOD_COUNT,
            MetricName::STRUCTURE_PROPERTY_COUNT,
            MetricName::STRUCTURE_IS_READONLY,
            MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY,
            MetricName::STRUCTURE_IS_DATA_CLASS,
            MetricName::STRUCTURE_IS_ABSTRACT,
            MetricName::STRUCTURE_IS_INTERFACE,
            MetricName::STRUCTURE_IS_EXCEPTION,
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        return $this->analyzeEligibleClasses($context);
    }

    /**
     * @return list<Violation>
     */
    private function analyzeEligibleClasses(AnalysisContext $context): array
    {
        if (!$this->options instanceof DataClassOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            if ($classInfo->subject?->toSymbolPath()->getType() !== SymbolType::Class_) {
                continue;
            }
            $violation = $this->evaluateClass($context, $classInfo);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function evaluateClass(AnalysisContext $context, SymbolInfo $classInfo): ?Violation
    {
        $subject = $classInfo->subject ?? throw new LogicException('Data class findings require an exact class declaration subject');
        $metrics = $context->metrics->get($subject->toSymbolPath());

        // Apply @qmx-threshold overrides for this class
        $effectiveOptions = $this->getEffectiveOptions(
            $context,
            $this->options,
            $subject,
        );
        \assert($effectiveOptions instanceof DataClassOptions);

        if (DataClassExclusionCheck::isExcluded($metrics, $effectiveOptions)) {
            return null;
        }

        $woc = $metrics->get(MetricName::STRUCTURE_WOC);
        if ($woc === null) {
            return null;
        }

        $wocValue = (int) $woc;
        $wmcValue = (int) ($metrics->get(MetricName::STRUCTURE_WMC) ?? 0);

        // Data Class: high WOC (public surface) + low WMC (complexity)
        if ($wocValue < $effectiveOptions->wocThreshold || $wmcValue > $effectiveOptions->wmcThreshold) {
            return null;
        }

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'Data Class detected: high public surface (WOC=%d%%, threshold %d%%) with low complexity (WMC=%d, threshold %d). Consider encapsulating behavior or using a DTO pattern',
                $wocValue,
                $effectiveOptions->wocThreshold,
                $wmcValue,
                $effectiveOptions->wmcThreshold,
            ),
            severity: Severity::Warning,
            metricValue: $wocValue,
            recommendation: 'Add behavior methods that operate on the data, or confirm this is intentionally a DTO.',
        );
    }

    /**
     * @return class-string<DataClassOptions>
     */
    public static function getOptionsClass(): string
    {
        return DataClassOptions::class;
    }

    /**
     * `design.data-class` reports WOC (`$wocValue`) as `metricValue` — see
     * the emission above — the only one of the rule's two gating axes that
     * reaches the `Violation`: emission requires the conjunction
     * `$wocValue < $effectiveOptions->wocThreshold ||
     * $wmcValue > $effectiveOptions->wmcThreshold` to be **false**
     * ({@see evaluateClass()}, line 116), i.e. `woc >= wocThreshold`
     * (inclusive). Higher WOC on the reported axis is worse — a higher
     * public-surface percentage with the WMC gate still satisfied is a
     * stronger Data Class signal. WMC's own gate is unaffected by this
     * declaration: it is not reported and therefore not baselineable on its
     * own terms (ADR 0017 — a compound rule is
     * baselined only on the axis it actually reports).
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
