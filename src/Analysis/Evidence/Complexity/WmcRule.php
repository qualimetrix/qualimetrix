<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
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
 * Rule that checks WMC (Weighted Methods per Class) at class level.
 *
 * WMC is the sum of cyclomatic complexities of all methods in a class.
 * It combines size and complexity into a single metric:
 * - WMC <= 30: simple class
 * - WMC 31-50: medium complexity
 * - WMC > 50: complex class requiring refactoring
 */
#[CliAlias('wmc-warning', 'warning')]
#[CliAlias('wmc-error', 'error')]
#[CliAlias('wmc-exclude-data-classes', 'excludeDataClasses')]
final class WmcRule extends AbstractRule
{
    public const string NAME = 'complexity.wmc';
    public const string DOCS_PAGE = 'rules/complexity.md';

    public const int REMEDIATION_MINUTES = 30;
    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Weighted Methods per Class (sum of method complexities)';
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
        return [MetricName::STRUCTURE_WMC, MetricName::STRUCTURE_IS_DATA_CLASS, MetricName::STRUCTURE_METHOD_COUNT];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof WmcOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $violation = $this->violationForClass($classInfo, $context, $this->options);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function violationForClass(SymbolInfo $classInfo, AnalysisContext $context, WmcOptions $options): ?Violation
    {
        $subject = $classInfo->subject ?? throw new LogicException('WMC findings require an exact class declaration subject');
        if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return null;
        }

        $metrics = $context->metrics->get($subject->toSymbolPath());
        if ($options->excludeDataClasses && $metrics->get(MetricName::STRUCTURE_IS_DATA_CLASS) === 1) {
            return null;
        }

        $wmc = $metrics->get(MetricName::STRUCTURE_WMC);
        if ($wmc === null) {
            return null;
        }

        $wmcValue = (int) $wmc;
        /** @var WmcOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($wmcValue);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $methodCount = $metrics->get(MetricName::STRUCTURE_METHOD_COUNT);

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'WMC (Weighted Methods per Class) is %d, exceeds threshold of %d. Simplify methods or split the class',
                $wmcValue,
                $threshold,
            ),
            severity: $severity,
            metricValue: $wmcValue,
            recommendation: $this->buildRecommendation($wmcValue, $threshold, $methodCount !== null ? (int) $methodCount : null),
            threshold: $threshold,
        );
    }

    /**
     * Builds a contextual recommendation based on avg method complexity.
     */
    private function buildRecommendation(int $wmcValue, int $threshold, ?int $methodCount): string
    {
        if ($methodCount === null || $methodCount === 0) {
            return \sprintf('WMC: %d (threshold: %d) — weighted method complexity is high', $wmcValue, $threshold);
        }

        $avgCcn = $wmcValue / $methodCount;

        if ($avgCcn < 3.0) {
            return \sprintf(
                'WMC: %d across %d methods (avg %.1f) — many methods, consider splitting the class',
                $wmcValue,
                $methodCount,
                $avgCcn,
            );
        }

        if ($avgCcn >= 5.0) {
            return \sprintf(
                'WMC: %d across %d methods (avg %.1f) — some methods are very complex',
                $wmcValue,
                $methodCount,
                $avgCcn,
            );
        }

        return \sprintf(
            'WMC: %d across %d methods (avg %.1f) — weighted method complexity is high',
            $wmcValue,
            $methodCount,
            $avgCcn,
        );
    }

    /**
     * @return class-string<WmcOptions>
     */
    public static function getOptionsClass(): string
    {
        return WmcOptions::class;
    }

    /**
     * `complexity.wmc` (this rule's channel prefix, despite the class living
     * under the Complexity leaf) reports WMC (`$wmcValue` — see the
     * emission above) as `metricValue`, judged worse the higher it goes:
     * {@see WmcOptions::getSeverity()}'s `$value >= $this->error` (line 76)
     * / `$value >= $this->warning` (line 80).
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
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
