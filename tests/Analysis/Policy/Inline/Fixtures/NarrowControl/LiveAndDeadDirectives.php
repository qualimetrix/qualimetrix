<?php

declare(strict_types=1);

namespace Fixtures\NarrowControl;

/**
 * The pair every directive fixture needs: one that certainly does something and
 * one that certainly does not.
 *
 * A detector whose addressing is broken answers alike for both, and a fixture
 * carrying only one of them cannot tell that apart from a correct answer. The
 * method takes seven parameters, which the default long-parameter-list
 * boundaries report, and has a cyclomatic complexity of one, which no boundary
 * on earth reports.
 */
final class LiveAndDeadDirectives
{
    /**
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=12 -- effective: without it the
     *                seven parameters below are reported.
     * @qmx-threshold complexity.cyclomatic warning=50 error=80 -- inert: a straight-line method
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
