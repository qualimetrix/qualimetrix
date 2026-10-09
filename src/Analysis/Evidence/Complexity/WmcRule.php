<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\FlagExcludes;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;

use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that checks WMC (Weighted Methods per Class) at class level.
 *
 * WMC is the sum of cyclomatic complexities of all methods in a class.
 * It combines size and complexity into a single metric. The warning and
 * error thresholds are owned by {@see WmcOptions}.
 *
 * Besides the published `complexity.wmc` value, also reads
 * `design.is-data-class` (skip data classes when configured) and
 * `size.method-count` (skip trivial classes).
 */
#[CliAlias('wmc-warning', 'warning')]
#[CliAlias('wmc-error', 'error')]
#[CliAlias('wmc-exclude-data-classes', 'excludeDataClasses')]
final class WmcRule extends AbstractRule
{
    public const string NAME = 'complexity.wmc';
    public const string DOCS_PAGE = 'rules/complexity.md';

    public const int REMEDIATION_MINUTES = 30;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Checks Weighted Methods per Class (sum of method complexities)';
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof WmcOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        $declaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allClassDeclarations() as $classInfo) {
            $finding = $this->findingForClass($classInfo, $context, $this->options, $declaration);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function findingForClass(SymbolInfo $classInfo, AnalysisContext $context, WmcOptions $options, ChannelDeclaration $declaration): ?Finding
    {
        $subject = $classInfo->subject ?? throw new LogicException('WMC findings require an exact class declaration subject');
        $metrics = null;
        if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Class_, PopulationIdentity::subject($subject), $declaration, (function () use ($context, $subject, &$metrics, &$options): iterable {
            yield GateInput::kind('class-coordinate', $subject->toSymbolPath()->getType());
            $metrics = $context->metrics->getSubject($subject);
            $options = $this->getEffectiveOptions($context, $options, $subject);
            yield GateInput::metrics('exclude-data-classes', $metrics, option: $options->excludeDataClasses);
            yield GateInput::metrics('class-value', $metrics);
        })())) {
            return null;
        }
        $wmc = $metrics->get(MetricName::COMPLEXITY_WMC);
        $wmcValue = (int) $wmc;

        /** @var WmcOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($wmcValue);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $methodCount = $metrics->get(MetricName::SIZE_METHOD_COUNT);

        return new Finding(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf(
                'WMC (Weighted Methods per Class) is %d, ' . ThresholdCrossing::of($wmcValue, $threshold)->value . ' threshold of %d. Simplify methods or split the class',
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
     * `complexity.wmc` reports WMC (`$wmcValue` — see the
     * emission above) as `metricValue`, judged worse the higher it goes:
     * {@see WmcOptions::getSeverity()}'s `$value >= $this->error`
     * / `$value >= $this->warning`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::judging(
                WorseDirection::Higher,
                JudgedMetrics::of(MetricName::COMPLEXITY_WMC),
                SymbolLevel::Class_,
            )->withGates(
                new PopulationGate('class-coordinate', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KindIn('class-coordinate', [SymbolType::Class_]), 'The subject is outside the class coordinate.'),
                new PopulationGate('exclude-data-classes', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new FlagExcludes('exclude-data-classes', MetricName::DESIGN_IS_DATA_CLASS, 1, true), 'The configured class exclusion applies.'),
                new PopulationGate('class-value', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KeyPresent('class-value', [MetricName::COMPLEXITY_WMC]), 'The class metric was not published.'),
            ),
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
