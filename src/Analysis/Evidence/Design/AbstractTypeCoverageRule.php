<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * How one type-coverage dimension is judged, for the three rules that judge
 * one each.
 *
 * The dimensions differ only in which pair of metrics they read and how they
 * name what is untyped; the walk over class declarations, the
 * `@qmx-threshold` lookup, the "no such declarations here" guard and the
 * shape of the finding are the same question asked three times. They live
 * here so that a change to how a coverage shortfall is reported cannot reach
 * two of the three.
 *
 * Each subclass still declares its own name, documentation page and remediation
 * estimate: those are what the three rules are *for*, and a rule that inherited
 * them would be a rule nothing distinguishes. The channel *declaration* is
 * inherited, because it says the same thing three times — a class-level
 * magnitude that is worse the lower it goes — and the name it carries comes
 * from {@see channelName()} by late static binding, which is how
 * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader}
 * already reads an inherited declaration.
 *
 * @qmx-ignore health.cohesion -- One judgement plus the four hooks that name the dimension it judges; the hooks are abstract protocol, and protocol shares no field with the walk that calls it.
 */
abstract class AbstractTypeCoverageRule extends AbstractRule
{
    public function getName(): string
    {
        return static::channelName();
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Design;
    }

    /**
     * Coverage is a percentage, and less of it is worse debt — hence a
     * magnitude channel whose worse direction is `Lower`. It reports at class
     * level because that is the declaration whose members are counted.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        $name = static::channelName();

        return [
            $name => ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_),
        ];
    }

    /** Shared by all three dimensions — a coverage percentage is a real measured magnitude. */
    public const ChannelShape SHAPE = ChannelShape::Magnitude;

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof TypeCoverageOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Type coverage findings require an exact class declaration subject');

            if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
                continue;
            }

            $finding = $this->judge($context, $subject, $classInfo, $context->metrics->get($subject->toSymbolPath()));

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * One class's shortfall in this dimension, or null when there is none.
     *
     * Null covers two different answers on purpose: nothing of this kind is
     * declared here, so there is nothing to type; and enough of it is typed.
     * Both mean "no finding", and neither is worth a separate return type.
     */
    private function judge(
        AnalysisContext $context,
        MetricSubject $subject,
        SymbolInfo $classInfo,
        MetricBag $metrics,
    ): ?Finding {
        $total = $metrics->get($this->totalMetric());

        if ($total === null || (int) $total <= 0) {
            return null;
        }

        $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
        \assert($effectiveOptions instanceof TypeCoverageOptions);

        $coverage = (float) ($metrics->get($this->coverageMetric()) ?? 0.0);
        $severity = $effectiveOptions->getSeverity($coverage);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;

        return new Finding(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: $this->getName(),
            message: \sprintf(
                '%s type coverage is %.1f%% (minimum: %.1f%%). %s',
                $this->label(),
                $coverage,
                $threshold,
                $this->hint(),
            ),
            severity: $severity,
            metricValue: $coverage,
            recommendation: \sprintf('%s type coverage: %.1f%% (threshold: %.1f%%) — missing type declarations', $this->label(), $coverage, $threshold),
            threshold: $threshold,
        );
    }

    /** The channel this dimension publishes, which is also the rule's name. */
    abstract protected static function channelName(): string;

    /**
     * The count of declarations of this dimension. Zero of them means the
     * class has nothing to type, which is not a shortfall.
     */
    abstract protected function totalMetric(): string;

    /** The percentage of those declarations that carry a type. */
    abstract protected function coverageMetric(): string;

    /** How the message names the dimension ("Parameter", "Return", "Property"). */
    abstract protected function label(): string;

    /** What the author is told to do about it. */
    abstract protected function hint(): string;
}
