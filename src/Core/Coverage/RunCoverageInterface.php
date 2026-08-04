<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Coverage;

use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\ViolationChannel;

/**
 * What a completed run actually evaluated.
 *
 * The contract lives in Core, not in the orchestration layer that implements
 * it, because its consumers sit in domains that may depend on Core alone.
 * Placing the answer next to its producer would force those consumers into an
 * upward dependency.
 *
 * The producer is the centre: it owns the discovery inventory, the parse and
 * worker failures, the enabled-rule set, and the exclusion configuration.
 * Rules contribute only what the centre cannot see, as a sparse list of
 * deviations — never a per-symbol map.
 */
interface RunCoverageInterface
{
    /**
     * What the run can prove about this channel on this symbol.
     *
     * Never returns null: "nothing is known" is itself an answer, expressed
     * as {@see ScopeCoverageStatus::Indeterminate}. An implementation that
     * returned null would push the distinction between "not evaluated" and
     * "unknown" onto every caller.
     */
    public function forSymbol(ViolationChannel $channel, SymbolPath $symbol): ScopeCoverage;

    /**
     * What the run can prove about this channel as a whole.
     *
     * This is the question aggregate and graph findings ask, since their
     * identity spans symbols and no single symbol's coverage settles it.
     */
    public function forChannel(ViolationChannel $channel): ScopeCoverage;
}
