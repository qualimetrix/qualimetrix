<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Coupling;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that checks ClassRank (PageRank on dependency graph) at class level.
 *
 * ClassRank identifies the most "important" classes in the codebase by analyzing
 * the dependency graph using the PageRank algorithm. Classes with high ClassRank
 * are critical hubs where changes have wide-reaching impact.
 */
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

        foreach ($classes as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $classRank = $metrics->get(MetricName::COUPLING_CLASS_RANK);

            if ($classRank === null) {
                continue;
            }

            $rankValue = (float) $classRank;

            // Apply @qmx-threshold overrides and re-scale
            /** @var ClassRankOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $classInfo->file, $classInfo->line ?? 1);
            $effectiveScaledWarning = $effectiveOptions->warning / $scaleFactor;
            $effectiveScaledError = $effectiveOptions->error / $scaleFactor;

            $severity = self::getSeverityForScaledThresholds($rankValue, $effectiveScaledWarning, $effectiveScaledError);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error
                    ? $effectiveScaledError
                    : $effectiveScaledWarning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
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
        }

        return $violations;
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
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'class-rank-warning' => 'warning',
            'class-rank-error' => 'error',
        ];
    }
}
