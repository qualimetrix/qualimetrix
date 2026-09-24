<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportCoverage;

final readonly class CoverageNarrator
{
    /**
     * The sentences a human format prints about what the run covered: the
     * files it read and, unless it covered the whole project, the project
     * scope it was judged against.
     *
     * @return list<string>
     */
    public static function lines(Report $report): array
    {
        $lines = [];
        if ($report->coverage !== null) {
            $lines[] = self::describe($report->coverage);
        }

        $projectScope = $report->projectScope?->describe();
        if ($projectScope !== null) {
            $lines[] = $projectScope;
        }

        return $lines;
    }

    public static function describe(ReportCoverage $coverage): string
    {
        if (!$coverage->isComplete()) {
            return \sprintf(
                'Analysis incomplete: %d of %d discovered PHP file(s) failed; policy results are not authoritative.',
                $coverage->failed,
                $coverage->discovered,
            );
        }

        if ($coverage->discovered === 0) {
            return 'No PHP files were discovered.';
        }

        if ($coverage->analyzed === 0 && $coverage->generatedExcluded > 0) {
            return \sprintf(
                'Analysis complete: all %d discovered PHP file(s) were intentionally excluded as generated.',
                $coverage->generatedExcluded,
            );
        }

        return \sprintf(
            'Analysis complete: %d analyzed, %d generated file(s) excluded.',
            $coverage->analyzed,
            $coverage->generatedExcluded,
        );
    }
}
