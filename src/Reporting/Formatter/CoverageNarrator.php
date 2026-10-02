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
            return self::describeIncomplete($coverage);
        }

        if ($coverage->analyzed === 0 && $coverage->excluded > 0) {
            return self::describeNamedExclusion($coverage);
        }

        if ($coverage->discovered === 0) {
            return 'No PHP files were discovered.';
        }

        if ($coverage->analyzed === 0 && $coverage->generatedExcluded > 0) {
            return self::describeGeneratedExclusion($coverage);
        }

        return self::describeComplete($coverage);
    }

    private static function describeIncomplete(ReportCoverage $coverage): string
    {
        $kinds = self::failureKinds($coverage);

        return \sprintf(
            'Analysis incomplete: %d of %d discovered entries failed%s; policy results are not authoritative.',
            $coverage->failed,
            $coverage->discovered,
            $kinds === [] ? '' : ' (' . implode(', ', $kinds) . ')',
        );
    }

    /** @return list<string> */
    private static function failureKinds(ReportCoverage $coverage): array
    {
        $byKind = [];
        foreach ($coverage->failures as $failure) {
            $byKind[$failure->kind] = ($byKind[$failure->kind] ?? 0) + 1;
        }
        ksort($byKind);
        $kinds = [];
        foreach ($byKind as $kind => $count) {
            $kinds[] = \sprintf('%d %s', $count, $kind);
        }

        return $kinds;
    }

    private static function describeNamedExclusion(ReportCoverage $coverage): string
    {
        return \sprintf(
            'Nothing analysed: %d named path(s) left out by exclude patterns%s.',
            $coverage->excluded,
            $coverage->generatedExcluded > 0 ? \sprintf(', %d PHP file(s) excluded as generated', $coverage->generatedExcluded) : '',
        );
    }

    private static function describeGeneratedExclusion(ReportCoverage $coverage): string
    {
        return \sprintf(
            'Analysis complete: all %d discovered PHP file(s) were intentionally excluded as generated.',
            $coverage->generatedExcluded,
        );
    }

    private static function describeComplete(ReportCoverage $coverage): string
    {
        return \sprintf(
            'Analysis complete: %d analyzed, %d generated file(s) excluded%s.',
            $coverage->analyzed,
            $coverage->generatedExcluded,
            $coverage->excluded > 0 ? \sprintf(', %d named path(s) left out by exclude patterns', $coverage->excluded) : '',
        );
    }
}
