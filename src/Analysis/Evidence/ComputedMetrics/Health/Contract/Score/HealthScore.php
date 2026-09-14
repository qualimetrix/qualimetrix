<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score;

/**
 * One health dimension with its score, its decomposition and the share of the
 * subject it was computed over.
 *
 * @qmx-threshold code-smell.constructor-overinjection warning=9 error=9 — Eight published facts about one score, not eight injected collaborators: this record has no behaviour to split and every field is read by a formatter.
 * @qmx-threshold code-smell.long-parameter-list warning=9 error=9 — Same reason: the eighth field is what a score covers, which belongs to the score and to nothing else.
 *
 * `$coverage` has no default on purpose: a default would let a new producer
 * publish a score that silently says nothing about what it covers, which is the
 * state ADR 0062 set out to end.
 */
final readonly class HealthScore
{
    /**
     * @param list<DecompositionItem> $decomposition
     * @param list<HealthContributor> $worstContributors
     */
    public function __construct(
        public string $name,
        public ?float $score,
        public string $label,
        public float $warningThreshold,
        public float $errorThreshold,
        public HealthCoverage $coverage,
        public array $decomposition = [],
        public array $worstContributors = [],
    ) {}
}
