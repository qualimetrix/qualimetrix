<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Design;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;

/**
 * Evaluates the four independent Lanza & Marinescu God Class criteria (WMC,
 * LCOM, TCC, class LOC) for a single class.
 *
 * Extracted out of {@see GodClassRule::analyze()} so that each criterion is a
 * small, independently testable predicate instead of four inline branches
 * multiplying the enclosing method's NPath complexity. This class performs
 * no AST traversal — it only reads metrics already computed by collectors,
 * same as the rule it serves (see CLAUDE.md §2, §3).
 *
 * A criterion is omitted from the result list entirely when its backing
 * metric is missing (not merely unmatched) — the caller uses the resulting
 * count as the "evaluable criteria" denominator.
 */
final class GodClassCriteriaEvaluator
{
    /**
     * @return list<GodClassCriterionResult>
     */
    public static function evaluate(MetricBag $metrics, GodClassOptions $options): array
    {
        $results = [];

        foreach ([self::wmc(...), self::lcom(...), self::tcc(...), self::classLoc(...)] as $criterion) {
            $result = $criterion($metrics, $options);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * WMC >= wmcThreshold (high complexity).
     */
    private static function wmc(MetricBag $metrics, GodClassOptions $options): ?GodClassCriterionResult
    {
        $wmc = $metrics->get(MetricName::STRUCTURE_WMC);
        if ($wmc === null) {
            return null;
        }

        $value = (int) $wmc;
        $matched = $value >= $options->wmcThreshold;

        return new GodClassCriterionResult(
            matched: $matched,
            message: $matched ? \sprintf('high WMC (%d >= %d)', $value, $options->wmcThreshold) : '',
        );
    }

    /**
     * LCOM4 >= lcomThreshold (low cohesion), vetoed when TCC >= 0.5 — a class
     * that is already tightly cohesive by TCC doesn't get penalized for LCOM
     * too, so this criterion is treated as not evaluable in that case.
     */
    private static function lcom(MetricBag $metrics, GodClassOptions $options): ?GodClassCriterionResult
    {
        $lcom = $metrics->get(MetricName::STRUCTURE_LCOM);
        if ($lcom === null) {
            return null;
        }

        $tcc = $metrics->get(MetricName::COHESION_TCC);
        if ($tcc !== null && (float) $tcc >= 0.5) {
            return null;
        }

        $value = (int) $lcom;
        $matched = $value >= $options->lcomThreshold;

        return new GodClassCriterionResult(
            matched: $matched,
            message: $matched ? \sprintf('high LCOM (%d >= %d)', $value, $options->lcomThreshold) : '',
        );
    }

    /**
     * TCC < tccThreshold (low tight class cohesion — inverted, low is bad).
     */
    private static function tcc(MetricBag $metrics, GodClassOptions $options): ?GodClassCriterionResult
    {
        $tcc = $metrics->get(MetricName::COHESION_TCC);
        if ($tcc === null) {
            return null;
        }

        $value = (float) $tcc;
        $matched = $value < $options->tccThreshold;

        return new GodClassCriterionResult(
            matched: $matched,
            message: $matched ? \sprintf('low TCC (%.2f < %.2f)', $value, $options->tccThreshold) : '',
        );
    }

    /**
     * classLoc >= classLocThreshold (large size).
     */
    private static function classLoc(MetricBag $metrics, GodClassOptions $options): ?GodClassCriterionResult
    {
        $classLoc = $metrics->get(MetricName::SIZE_CLASS_LOC);
        if ($classLoc === null) {
            return null;
        }

        $value = (int) $classLoc;
        $matched = $value >= $options->classLocThreshold;

        return new GodClassCriterionResult(
            matched: $matched,
            message: $matched ? \sprintf('large size (%d >= %d LOC)', $value, $options->classLocThreshold) : '',
        );
    }
}
