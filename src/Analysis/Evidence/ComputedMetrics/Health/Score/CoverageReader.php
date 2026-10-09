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
     * @param list<array{key: string, sources: list<string>, label: string, direction: string, coverage: array{count: string, unit: CoverageUnit}|null}>|null $inputs
     */
    public function read(string $dimension, callable $readProjectMetric, ?array $inputs = null): HealthCoverage
    {
        $narrowest = null;
        $emptyPopulation = null;

        foreach ($inputs ?? $this->decomposition->inputsFor($dimension, SymbolLevel::Project) as $input) {
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
     * An absent count means no input was measured: the
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
        return HealthCoverage::over(self::count($readProjectMetric($count) ?? 0, $count), $eligible, $unit, $count);
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

        return self::count($readProjectMetric($metric) ?? throw new LogicException(\sprintf(
            'Coverage unit "%s" names "%s" as its population, and this run publishes no such project metric.',
            $unit->value,
            $metric,
        )), $metric);
    }

    /** @param callable(string): (int|float|null) $readProjectMetric */
    public function forInput(string $dimension, string $key, callable $readProjectMetric): HealthCoverage
    {
        foreach ($this->decomposition->inputsFor($dimension, SymbolLevel::Project) as $input) {
            if ($input['key'] === $key && $input['coverage'] !== null) {
                $spec = $input['coverage'];
                $eligible = self::population($spec['unit'], $readProjectMetric);

                return $eligible === 0
                    ? HealthCoverage::notApplicable(\sprintf('no %s were measured', $spec['unit']->value))
                    : self::measured($spec['count'], $spec['unit'], $eligible, $readProjectMetric);
            }
        }

        return HealthCoverage::notApplicable('this input has no declared symbol population');
    }

    private static function count(int|float $value, string $metric): int
    {
        if (!is_finite((float) $value) || $value < 0 || $value > \PHP_INT_MAX || floor((float) $value) !== (float) $value) {
            throw new LogicException(\sprintf('Coverage metric "%s" must be a non-negative finite integer.', $metric));
        }

        return (int) $value;
    }
}
