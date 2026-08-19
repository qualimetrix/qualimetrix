<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use LogicException;
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
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that checks ClassRank (PageRank on dependency graph) at class level.
 *
 * ClassRank identifies the most "important" classes in the codebase by analyzing
 * the dependency graph using the PageRank algorithm. Classes with high ClassRank
 * are critical hubs where changes have wide-reaching impact.
 */
#[CliAlias('class-rank-warning', 'warning')]
#[CliAlias('class-rank-error', 'error')]
final class ClassRankRule extends AbstractRule
{
    public const string NAME = 'coupling.class-rank';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks ClassRank (PageRank on dependency graph) to identify critical hub classes';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Coupling;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::COUPLING_CLASS_RANK];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ClassRankOptions || !$this->options->isEnabled()) {
            return [];
        }

        // Collect all classes first — we need the count for threshold scaling
        $classes = iterator_to_array($context->metrics->all(SymbolType::Class_), false);
        $classCount = \count($classes);

        if ($classCount === 0) {
            return [];
        }

        // Scale thresholds by project size. PageRank sums to 1.0, so individual
        // ranks dilute as class count grows. sqrt(classCount/100) normalizes:
        // - 100 classes: thresholds unchanged (scale factor = 1.0)
        // - 1600 classes: thresholds / 4 (catches more hubs)
        // - 25 classes: thresholds * 2 (avoids false positives)
        $scaleFactor = self::computeScaleFactor($classCount);

        $violations = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $violation = $this->violationForClass($classInfo, $context, $scaleFactor, $classCount);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function violationForClass(
        SymbolInfo $classInfo,
        AnalysisContext $context,
        float $scaleFactor,
        int $classCount,
    ): ?Violation {
        $subject = $classInfo->subject ?? throw new LogicException('ClassRank findings require an exact class declaration subject');
        if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return null;
        }

        $classRank = $context->metrics->get($subject->toSymbolPath())->get(MetricName::COUPLING_CLASS_RANK);
        if ($classRank === null) {
            return null;
        }

        $rankValue = (float) $classRank;

        /** @var ClassRankOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
        $effectiveScaledWarning = $effectiveOptions->warning / $scaleFactor;
        $effectiveScaledError = $effectiveOptions->error / $scaleFactor;
        $severity = self::getSeverityForScaledThresholds($rankValue, $effectiveScaledWarning, $effectiveScaledError);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveScaledError : $effectiveScaledWarning;

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'ClassRank is %.4f, exceeds threshold of %.4f (scaled for %d classes). This class is a critical hub — changes have wide impact',
                $rankValue,
                $threshold,
                $classCount,
            ),
            severity: $severity,
            metricValue: $rankValue,
            recommendation: \sprintf('ClassRank: %.4f (threshold: %.4f) — coupling hotspot, many depend on this', $rankValue, $threshold),
            threshold: $threshold,
        );
    }

    /**
     * Compute the scale factor for threshold adjustment based on class count.
     *
     * Uses sqrt(classCount / 100) so that thresholds are unchanged at 100 classes,
     * decrease for larger projects, and increase for smaller ones.
     */
    public static function computeScaleFactor(int $classCount): float
    {
        if ($classCount <= 0) {
            return 1.0;
        }

        return sqrt($classCount / 100);
    }

    private static function getSeverityForScaledThresholds(
        float $value,
        float $scaledWarning,
        float $scaledError,
    ): ?Severity {
        if ($value >= $scaledError) {
            return Severity::Error;
        }

        if ($value >= $scaledWarning) {
            return Severity::Warning;
        }

        return null;
    }

    /**
     * @return class-string<ClassRankOptions>
     */
    public static function getOptionsClass(): string
    {
        return ClassRankOptions::class;
    }

    /**
     * `coupling.class-rank` is declared `occurrence` — a **decision, not a
     * derivation** (ADR 0017) — even though it reports a
     * real number (`$rankValue`, see the emission above). ClassRank is an
     * iterative PageRank normalised over the *whole project*: it moves
     * whenever anything anywhere is added or removed, and this rule's own
     * threshold is rescaled for the current class count to compensate
     * (`$scaleFactor = self::computeScaleFactor($classCount)`, applied to
     * `$effectiveOptions->warning`/`->error` before comparison — see
     * {@see analyze()}). A stored raw rank from an earlier run is therefore
     * not a boundary in a later run's units; no rounding or tolerance fixes
     * a units mismatch. Bounded by `count` instead, the entry says "this
     * class is an accepted coupling hotspot" — the only claim the number
     * actually supports. Ratcheting the coupling ClassRank stands for is
     * `coupling.cbo`'s job; that channel stays `magnitude`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::occurrence(),
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
