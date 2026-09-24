<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\CoverageUnit;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The share of its subject a project-level score was computed over.
 *
 * A class of its own rather than a method on the summary builder: "what was
 * this score computed from" and "how much of the subject did that reach" are
 * separate questions, and the builder said so by growing past its own weighted
 * -method-count threshold when the second one moved in.
 *
 * Numerators are the `.count` every aggregate already publishes; nothing new is
 * collected.
 */
final readonly class CoverageReader
{
    public function __construct(
        private HealthDecompositionCatalog $decomposition = new HealthDecompositionCatalog(),
    ) {}

    /**
     * The narrowest input wins: a score is only as much a statement about the
     * project as its least-covered term, and reporting the widest — or a mean
     * of the two — would hide exactly the case this exists for, a cohesion
     * score whose TCC term saw a third of the classes while its LCOM term saw
     * nearly all of them. The `basis` names whose count was reported, so the
     * reader is not left guessing which term is the narrow one.
     *
     * @param callable(string): (int|float|null) $readProjectMetric
     */
    public function read(string $dimension, callable $readProjectMetric): HealthCoverage
    {
        $narrowest = null;
        $emptyPopulation = null;

        foreach ($this->decomposition->inputsFor($dimension, SymbolLevel::Project) as $input) {
            $spec = $input['coverage'];

            if ($spec === null) {
                continue;
            }

            $eligible = self::population($spec['unit'], $readProjectMetric);

            if ($eligible <= 0) {
                $emptyPopulation ??= \sprintf('no %s were measured', $spec['unit']->value);
                continue;
            }

            $candidate = self::measured($spec['count'], $spec['unit'], $eligible, $readProjectMetric);

            if ($narrowest === null || $candidate->ratio < $narrowest->ratio) {
                $narrowest = $candidate;
            }
        }

        return $narrowest ?? HealthCoverage::notApplicable(
            $emptyPopulation ?? $this->decomposition->coverageAbsenceReason($dimension),
        );
    }

    /**
     * An absent count is a measured zero, not a missing field: the
     * aggregator publishes no key at all when nothing contributed
     * (`AggregationHelper::applyAggregations()` skips an empty value
     * list), the formula then read the aggregate through `?? 0` and
     * scored the subject anyway. That is the case worth publishing
     * loudest — a project of bare enums declares namespaces and gets no
     * distance from any of them.
     *
     * It also means a mistyped key is indistinguishable from it here,
     * which is why the key is not typed: it is derived from the line's
     * own aggregate by {@see HealthDecompositionCatalog::countOf()}.
     *
     * @param callable(string): (int|float|null) $readProjectMetric
     */
    private static function measured(string $count, CoverageUnit $unit, int $eligible, callable $readProjectMetric): HealthCoverage
    {
        return HealthCoverage::over((int) ($readProjectMetric($count) ?? 0), $eligible, $unit, $count);
    }

    /**
     * How much of the subject there was to cover.
     *
     * A population metric is written unconditionally from the run's own symbol
     * list, beside the aggregates themselves, so a run that publishes a health
     * score publishes all three — see
     * {@see \Qualimetrix\Analysis\Evidence\Measurement\Aggregation\NamespaceToProjectAggregator}.
     * An absent one is therefore a key this enum names and nothing writes, and
     * reading it as zero turned that into "no classes were measured": the
     * denominator's own failure, published as a statement about the code. There
     * is no run in which the honest answer is a number, so this refuses instead.
     *
     * @param callable(string): (int|float|null) $readProjectMetric
     */
    private static function population(CoverageUnit $unit, callable $readProjectMetric): int
    {
        $metric = $unit->populationMetric();

        return (int) ($readProjectMetric($metric) ?? throw new LogicException(\sprintf(
            'Coverage unit "%s" names "%s" as its population, and this run publishes no such project metric.',
            $unit->value,
            $metric,
        )));
    }
}
