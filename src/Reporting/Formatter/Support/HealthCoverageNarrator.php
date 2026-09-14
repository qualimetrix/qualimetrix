<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage;

/**
 * One line saying what share of the subject a health score speaks for.
 *
 * Beside {@see CoverageNarrator}, which narrates the other coverage in a
 * report — the share of discovered files that parsed. The two are different
 * subjects with the same word, which is why neither line uses the word alone.
 */
final readonly class HealthCoverageNarrator
{
    public static function describe(HealthCoverage $coverage): string
    {
        if (!$coverage->applicable) {
            return \sprintf('    Computed over: not applicable — %s', $coverage->reason);
        }

        return \sprintf(
            '    Computed over %d of %d %s (%.0f%%), from %s',
            $coverage->measured,
            $coverage->eligible,
            $coverage->unit?->value,
            ($coverage->ratio ?? 0.0) * 100,
            $coverage->basis,
        );
    }
}
