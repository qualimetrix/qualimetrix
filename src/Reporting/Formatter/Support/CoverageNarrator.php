<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use Qualimetrix\Reporting\ReportCoverage;

final readonly class CoverageNarrator
{
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
