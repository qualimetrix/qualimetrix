<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Health;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;

/**
 * One line saying what share of the subject a health score speaks for.
 *
 * Beside {@see \Qualimetrix\Reporting\Formatter\CoverageNarrator}, which narrates the other coverage in a
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

    /**
     * The same statement without the indent or the basis, for the line a score
     * is printed on rather than a decomposition block below it.
     *
     * ADR 0062 publishes coverage beside the score; a surface that shows the
     * score and not this is a score over an unstated share of the subject.
     */
    public static function summarize(HealthCoverage $coverage): string
    {
        if (!$coverage->applicable) {
            return \sprintf('coverage: not applicable — %s', $coverage->reason);
        }

        return \sprintf(
            'computed over %d of %d %s (%.0f%%)',
            $coverage->measured,
            $coverage->eligible,
            $coverage->unit?->value,
            ($coverage->ratio ?? 0.0) * 100,
        );
    }

    /**
     * The statement every one of these coverages makes, when they all make the
     * same one; null when they differ and each has to speak for itself.
     *
     * A namespace drill-down gives every dimension the identical reason, and a
     * statement repeated six times stops being read.
     *
     * @param array<string, HealthScore> $scores
     */
    public static function sharedNote(array $scores): ?string
    {
        $notes = array_unique(array_map(
            static fn(HealthScore $score): string => self::summarize($score->coverage),
            array_values($scores),
        ));

        return \count($notes) === 1 ? reset($notes) : null;
    }

    /**
     * The whole statement as data, for a payload rather than a line of text.
     *
     * The two states carry the same keys so a consumer reads `state` instead of
     * inferring absence from a zero: an undefined coverage and a coverage of
     * nothing are different claims about the subject. `--format=json` and the
     * HTML payload both publish this record, so a key added here reaches both;
     * JSON sanitizes `ratio` at its own boundary.
     *
     * @return array{state: string, measured: ?int, eligible: ?int, ratio: ?float, unit: ?string, basis: ?string, reason: ?string}
     */
    public static function record(HealthCoverage $coverage): array
    {
        return [
            'state' => $coverage->applicable ? 'measured' : 'not-applicable',
            'measured' => $coverage->measured,
            'eligible' => $coverage->eligible,
            'ratio' => $coverage->ratio,
            'unit' => $coverage->unit?->value,
            'basis' => $coverage->basis,
            'reason' => $coverage->reason,
        ];
    }

    /**
     * The share alone, for a table cell. `n/a` and `0%` stay distinguishable:
     * an undefined coverage and a coverage of nothing are different claims.
     */
    public static function share(HealthCoverage $coverage): string
    {
        return $coverage->applicable
            ? \sprintf('%.0f%%', ($coverage->ratio ?? 0.0) * 100)
            : 'n/a';
    }
}
