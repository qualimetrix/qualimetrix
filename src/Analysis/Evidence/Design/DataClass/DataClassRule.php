<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\DataClass;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that detects Data Classes — classes whose public interface is mostly
 * data access and whose logic is thin.
 *
 * Follows Lanza & Marinescu: a Data Class exposes its state through accessors
 * and public properties instead of behaviour, so the share of functional
 * public methods (WOC) is low while complexity (WMC) stays low too. DTOs
 * declared as such (readonly or promoted-properties-only) are excluded.
 */
#[CliAlias('data-class-woc-threshold', 'wocThreshold')]
#[CliAlias('data-class-wmc-threshold', 'wmcThreshold')]
#[CliAlias('data-class-min-members', 'minMembers')]
#[CliAlias('data-class-exclude-readonly', 'excludeReadonly')]
#[CliAlias('data-class-exclude-promoted-only', 'excludePromotedOnly')]
#[CliAlias('data-class-exclude-exceptions', 'excludeExceptions')]
final class DataClassRule extends AbstractRule
{
    public const string NAME = 'design.data-class';
    public const string DOCS_PAGE = 'rules/design.md';

    public const int REMEDIATION_MINUTES = 30;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects classes whose public interface is mostly data access rather than behavior (Data Classes)';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [
            MetricName::DESIGN_WOC,
            MetricName::COMPLEXITY_WMC,
            MetricName::SIZE_METHOD_COUNT_TOTAL,
            MetricName::SIZE_PROPERTY_COUNT,
            MetricName::DESIGN_IS_READONLY,
            MetricName::DESIGN_IS_PROMOTED_PROPERTIES_ONLY,
            MetricName::DESIGN_IS_ABSTRACT,
            MetricName::DESIGN_IS_INTERFACE,
            MetricName::DESIGN_IS_EXCEPTION,
        ];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        return $this->analyzeEligibleClasses($context);
    }

    /**
     * @return list<Finding>
     */
    private function analyzeEligibleClasses(AnalysisContext $context): array
    {
        if (!$this->options instanceof DataClassOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            if ($classInfo->subject?->toSymbolPath()->getType() !== SymbolType::Class_) {
                continue;
            }
            $finding = $this->evaluateClass($context, $classInfo);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function evaluateClass(AnalysisContext $context, SymbolInfo $classInfo): ?Finding
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

        $woc = $metrics->get(MetricName::DESIGN_WOC);
        if ($woc === null) {
            return null;
        }

        $wocValue = (int) $woc;
        $wmcValue = (int) ($metrics->get(MetricName::COMPLEXITY_WMC) ?? 0);

        // Data Class: high WOC (public surface) + low WMC (complexity)
        if ($wocValue > $effectiveOptions->wocThreshold || $wmcValue > $effectiveOptions->wmcThreshold) {
            return null;
        }

        return new Finding(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf(
                'Data Class detected: only %d%% of the public interface is behavior (WOC, threshold %d%%) and complexity is low (WMC=%d, threshold %d). Consider encapsulating behavior or using a DTO pattern',
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
     * reaches the `Finding`: emission requires the disjunction
     * `$wocValue > $effectiveOptions->wocThreshold ||
     * $wmcValue > $effectiveOptions->wmcThreshold` to be **false**
     * ({@see evaluateClass()}), i.e. `woc <= wocThreshold` (inclusive).
     * Lower WOC on the reported axis is worse — the less of the public
     * interface carries behaviour, with the WMC gate still satisfied, the
     * stronger the Data Class signal. WMC's own gate is unaffected by this
     * declaration: it is not reported and therefore not baselineable on its
     * own terms (ADR 0017 — a compound rule is
     * baselined only on the axis it actually reports).
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_),
        ];
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
