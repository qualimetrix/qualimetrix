<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use Qualimetrix\Core\Violation\ChannelShape;
use Qualimetrix\Core\Violation\Violation;

/**
 * Renders the "accepted at 25, now 31" fragment a measured breach carries
 * (ADR 0017) — what makes a breach distinguishable
 * from a fresh violation without running `explain`.
 *
 * Shared by every formatter that decided to carry the accepted level in its
 * output (see `src/Reporting/README.md`), so the wording stays identical
 * across `text`, `checkstyle`, `gitlab`, `github` and `sarif`.
 */
final class AcceptedLevelNarrator
{
    /**
     * Returns the breach fragment, or `null` for every violation that is
     * not a measured breach — {@see Violation::$acceptedLevel} is `null` on
     * all of them, including findings a baseline never judged.
     */
    public static function describe(Violation $violation): ?string
    {
        $accepted = $violation->acceptedLevel;

        if ($accepted === null) {
            return null;
        }

        if ($accepted->shape() === ChannelShape::Occurrence) {
            // An occurrence channel's accepted level is a count. The group
            // size the mechanism compared it against is not something a
            // single Violation carries, so there is no honest "now" to print.
            return \sprintf('accepted at %s', $accepted->describe());
        }

        $current = self::formatCurrent($violation->metricValue);

        if ($current === null) {
            return \sprintf('accepted at %s', $accepted->describe());
        }

        return \sprintf('accepted at %s, now %s', $accepted->describe(), $current);
    }

    /**
     * Same trailing-zero trim as {@see \Qualimetrix\Core\Violation\AcceptedLevel::describe()}
     * applies to the accepted side, so "40.0" and "40" don't print differently
     * depending on which side of "now" they land on.
     */
    private static function formatCurrent(int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (\is_float($value) && !is_finite($value)) {
            return null;
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        $formatted = rtrim(rtrim(\sprintf('%.6F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
