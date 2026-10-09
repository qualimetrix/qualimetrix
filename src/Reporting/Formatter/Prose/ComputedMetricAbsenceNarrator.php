<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Prose;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricValueAbsence;
use Qualimetrix\Reporting\Report;

final readonly class ComputedMetricAbsenceNarrator
{
    /** @return list<string> */
    public static function lines(Report $report): array
    {
        return array_map(static function (ComputedMetricValueAbsence $absence): string {
            $reasons = [];
            if ($absence->missingKeysCount > 0) {
                $reasons[] = \sprintf('missing keys [%s] for %d subject(s)', implode(', ', $absence->missingKeys), $absence->missingKeysCount);
            }
            if ($absence->noValueCount > 0) {
                $reasons[] = \sprintf('no value for %d subject(s)', $absence->noValueCount);
            }
            $examples = array_map(static fn($subject): string => $subject->toCanonical(), $absence->subjects);

            return \sprintf('Computed metric %s (%s): not measured — %s%s', $absence->metricName, $absence->level->value, implode('; ', $reasons), $examples === [] ? '' : '; examples: ' . implode(', ', $examples));
        }, $report->computedMetricEvaluation->absences);
    }
}
