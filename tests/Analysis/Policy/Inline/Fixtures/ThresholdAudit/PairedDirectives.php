<?php

declare(strict_types=1);

namespace Fixtures\ThresholdAudit;

/**
 * Two threshold directives on one anchor: one that certainly does something
 * and one that certainly does not.
 *
 * The pair is the point. A detector whose addressing is broken answers "inert"
 * for both, and a single-directive fixture cannot tell that apart from a
 * correct answer. The method below takes seven parameters, which the default
 * long-parameter-list boundaries report, and has a cyclomatic complexity of
 * one, which no boundary on earth reports.
 */
final class PairedDirectives
{
    /**
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=12 — live: without it the
     *                seven parameters below are reported.
     * @qmx-threshold complexity.cyclomatic warning=50 error=80 — inert: a straight-line method
     *                never reaches any boundary, raised or default.
     */
    public function configure(
        string $one,
        string $two,
        string $three,
        string $four,
        string $five,
        string $six,
        string $seven,
    ): string {
        return $one . $two . $three . $four . $five . $six . $seven;
    }
}
