<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

/**
 * Everything the `suppressed` format publishes: what was kept out of the
 * report, by what, and which configured suppressors kept nothing out at all.
 *
 * `$all` is a multiset over mechanism × finding, not a set of findings — see
 * {@see SuppressedFinding}. Summing counts by mechanism therefore does not
 * yield the number of suppressed findings, and a formatter presenting both
 * must say so rather than let the arithmetic imply otherwise.
 */
final readonly class SuppressionComposition
{
    /**
     * @param list<SuppressedFinding> $all
     * @param list<InertSuppressor> $neverMatched
     */
    public function __construct(
        public array $all,
        public array $neverMatched = [],
    ) {}
}
