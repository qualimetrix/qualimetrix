<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/**
 * Why one finding in {@see RuleExclusionStats::$excludedFindings} was removed,
 * recorded by {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger} at
 * the moment it made that decision.
 *
 * `$producerRuleName` is the producer {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger::keeps()}
 * was called with — the same name the decision itself used, never
 * `Finding::$ruleName`. One rule class can publish findings under several
 * producer names (the computed-metric family), so a consumer that re-derives
 * "who excluded this" from the finding instead of reading this field can name
 * the wrong producer's configuration.
 *
 * A ledger half short-circuits on the first configured pattern that matches
 * (the same order {@see FindingExclusionLedger::keeps()} uses), so exactly
 * one of `$matchedPatterns` / `$matchedChannelPatterns` is non-empty for a
 * given attribution. Both are recorded as the *complete* set of patterns that
 * independently match this finding — not only the one the short-circuit
 * happened to reach first — because a consumer computing "which configured
 * patterns fired nothing this run" needs every pattern that could have fired,
 * not one arbitrary witness: two overlapping `suppress_paths` entries both
 * matching the same file must both count as fired, or the second is
 * misreported as dead.
 */
final readonly class RuleExclusionAttribution
{
    /**
     * @param list<string> $matchedPatterns Every `suppress_namespaces` (or, for a path
     *                                      exclusion, `suppress_paths`) pattern matching this finding.
     * @param list<array{selector: string, pattern: string}> $matchedChannelPatterns Every
     *                                                                               `suppress_namespace_channels` selector/pattern pair matching
     *                                                                               this finding. Populated only for a namespace-channel exclusion.
     */
    public function __construct(
        public string $producerRuleName,
        public bool $isPathExclusion,
        public array $matchedPatterns = [],
        public array $matchedChannelPatterns = [],
    ) {}
}
