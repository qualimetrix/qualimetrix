<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score;

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
     */
    /**
     * @param callable(string): (int|float|null) $readProjectMetric
     */
    public function read(string $dimension, callable $readProjectMetric, int $leafNamespaceCount): HealthCoverage
    {
        $narrowest = null;
        $emptyPopulation = null;

        foreach ($this->decomposition->inputsFor($dimension, SymbolLevel::Project) as $input) {
            $spec = $input['coverage'];

            if ($spec === null) {
                continue;
            }

            $eligible = $this->population($spec['unit'], $readProjectMetric, $leafNamespaceCount);

            if ($eligible <= 0) {
                $emptyPopulation ??= \sprintf('no %s were measured', $spec['unit']->value);
                continue;
            }

            // An absent count is a measured zero, not a missing field: the
            // formula read the aggregate through `?? 0` and scored the subject
            // anyway. That is the case worth publishing loudest.
            $candidate = HealthCoverage::over(
                (int) ($readProjectMetric($spec['count']) ?? 0),
                $eligible,
                $spec['unit'],
                $spec['count'],
            );

            if ($narrowest === null || $candidate->ratio < $narrowest->ratio) {
                $narrowest = $candidate;
            }
        }

        return $narrowest ?? HealthCoverage::notApplicable(
            $emptyPopulation ?? $this->decomposition->coverageAbsenceReason($dimension),
        );
    }

    /**
     * @param callable(string): (int|float|null) $readProjectMetric
     */
    private function population(CoverageUnit $unit, callable $readProjectMetric, int $leafNamespaceCount): int
    {
        $populationMetric = $unit->populationMetric();

        return $populationMetric === null
            ? $leafNamespaceCount
            : (int) ($readProjectMetric($populationMetric) ?? 0);
    }
}
