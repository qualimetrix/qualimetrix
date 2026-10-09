<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

/** Successful custom-formula absence, separate from published measurements. */
final readonly class ComputedMetricEvaluationSummary
{
    /** @var list<ComputedMetricValueAbsence> */
    public array $absences;

    /** @param list<ComputedMetricValueAbsence> $absences */
    public function __construct(array $absences = [])
    {
        $groups = [];
        foreach ($absences as $absence) {
            $key = $absence->metricName . ':' . $absence->level->value;
            $groups[$key] = isset($groups[$key]) ? $groups[$key]->merge($absence) : $absence;
        }
        ksort($groups, \SORT_STRING);
        $this->absences = array_values($groups);
    }

    public function merge(self $other): self
    {
        if ($other->absences === []) {
            return $this;
        }
        if ($this->absences === []) {
            return $other;
        }

        return new self([...$this->absences, ...$other->absences]);
    }
}
