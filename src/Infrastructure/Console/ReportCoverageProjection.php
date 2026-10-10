<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\CoverageFailure;
use Qualimetrix\Reporting\ReportCoverage;

/**
 * The run's coverage as a report publishes it: every failure named relative
 * to the project when it is inside the project, as the rest of the report is.
 */
final class ReportCoverageProjection
{
    public static function of(AnalysisCoverage $coverage, AbsolutePath $projectRoot): ReportCoverage
    {
        return new ReportCoverage(
            discovered: $coverage->discoveredFiles(),
            analyzed: $coverage->analyzedFilesCount(),
            generatedExcluded: $coverage->generatedExcludedFilesCount(),
            failed: $coverage->failedFilesCount(),
            excluded: $coverage->excludedCount(),
            failures: array_map(
                static fn(AnalysisFailure $failure): CoverageFailure => new CoverageFailure(
                    $failure->path->value(),
                    $failure->kind->value,
                    self::failureMessage($failure->message, $projectRoot),
                ),
                $coverage->failures,
            ),
        );
    }

    /**
     * A failure message names paths from two worlds: one inside the project
     * loses the project prefix, one outside it (a dependency) has no relative
     * form and keeps the absolute one.
     */
    public static function failureMessage(string $message, AbsolutePath $projectRoot): string
    {
        $prefix = rtrim($projectRoot->value(), '/');
        if ($prefix === '') {
            return $message;
        }

        return preg_replace(
            '#(?<![A-Za-z0-9._~/\\-])' . preg_quote($prefix, '#') . '/#',
            '',
            $message,
        ) ?? $message;
    }
}
