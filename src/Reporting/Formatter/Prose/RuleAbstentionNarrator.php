<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Prose;

use Qualimetrix\Analysis\Finding\Contract\Population\RuleAbstention;
use Qualimetrix\Reporting\Report;

final readonly class RuleAbstentionNarrator
{
    /** @return list<string> */
    public static function lines(Report $report, bool $verbose = false): array
    {
        $abstentions = $report->population->abstentions();
        if ($abstentions === []) {
            return [];
        }
        $units = [];
        foreach ($report->population->judgedCounts() as $judged) {
            $units[$judged['unit']] = ['judged' => ($units[$judged['unit']]['judged'] ?? 0) + $judged['count'], 'unjudged' => 0];
        }
        foreach ($abstentions as $absence) {
            $units[$absence->unit] = ['judged' => $units[$absence->unit]['judged'] ?? 0, 'unjudged' => ($units[$absence->unit]['unjudged'] ?? 0) + $absence->count];
        }
        ksort($units, \SORT_STRING);
        $counts = [];
        foreach ($units as $unit => $count) {
            $counts[] = \sprintf('%s: %d judged, %d not judged', $unit, $count['judged'], $count['unjudged']);
        }
        $lines = ['Rule population incomplete — ' . implode('; ', $counts) . '.'];
        if ($verbose) {
            foreach ($abstentions as $absence) {
                $lines[] = self::group($absence);
            }
        }
        return $lines;
    }

    private static function group(RuleAbstention $absence): string
    {
        return \sprintf('  %s / %s (%s), gate %s: %s — %d %s judgement(s) not judged%s', $absence->producer, $absence->channel->code, $absence->level->value, $absence->gate, $absence->reason, $absence->count, $absence->unit, $absence->examples === [] ? '' : '; examples: ' . implode(', ', $absence->examples));
    }
}
