<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/**
 * What one {@see RuleExecutionInterface::execute()} call did, as a value
 * rather than as two separate reads of mutable state.
 *
 * `$produced` and `$published` differ by exactly two things that run inside
 * {@see \Qualimetrix\Analysis\Finding\RuleExecution}: the per-rule
 * `suppress_namespaces`/`suppress_namespace_channels`/`suppress_paths` ledger,
 * and the per-finding channel selection in `published()` — the half of
 * `--only-rule`/`--disable-rule` that switches off one channel of a producer
 * that keeps running (the computed-metric family publishing seven channels
 * from one instance is the case this matters for). A finding either of those
 * two removed is absent from `$published` but still present in `$produced`.
 *
 * A **producer disabled entirely** is a third, earlier kind of selection that
 * neither collection ever sees: `activeRuleInstances()` drops such a rule
 * before `analyze()` runs, so it produces no findings at all — `$produced` is
 * not "everything the producer would have found", only everything the
 * producers this run actually executed found. An audit comparing `$produced`
 * against `$published` can therefore read a directive's effect on a running
 * producer, but has nothing to say about one a selector switched off outright.
 *
 * `$produced` retains every ledger-excluded finding unconditionally, unlike
 * {@see RuleExclusionStats::$excludedFindings}, which is opt-in specifically
 * to avoid paying for objects `--show-suppressed` will never display. This
 * field cannot make the same trade: the whole reason it exists is the
 * decision that "the audit reads `$produced`, not `$published`", and an
 * audit that only sometimes has data is not an audit. Measured on this
 * project's own `src` (856 files): 187 findings the per-rule ledger removes,
 * against 233 published — a fixed, small addition to what the run already
 * holds for the same duration, not the unbounded growth an opt-in exists to
 * prevent.
 */
final readonly class RuleExecutionResult
{
    /**
     * @param list<Finding> $produced Every finding rules and their configuration validators
     *                                produced, before the per-rule exclusion ledger and per-finding
     *                                channel selection ran. Empty for a producer this run never
     *                                executed at all.
     * @param list<Finding> $published The subset {@see RuleExecutionInterface::execute()} returns:
     *                                 `$produced` after the ledger and channel selection.
     * @param LevelActivity $levelActivity What this run's configuration let each producer do, recorded
     *                                     by the execution that decided it. Required rather than
     *                                     defaulted: a caller that has no answer says so with
     *                                     {@see LevelActivity::empty()}, and an omitted argument would
     *                                     read as "nothing is disabled" at every call site that forgot
     *                                     it.
     */
    public function __construct(
        public array $produced,
        public array $published,
        public RuleExclusionStats $exclusions,
        public LevelActivity $levelActivity,
    ) {}

    /**
     * Combines two runs' results honestly rather than picking one side:
     * `$produced` and `$published` concatenate like {@see \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult::merge()}
     * concatenates `$findings`, and the exclusion tallies add per rule.
     */
    public function merge(self $other): self
    {
        return new self(
            produced: [...$this->produced, ...$other->produced],
            published: [...$this->published, ...$other->published],
            levelActivity: LevelActivity::fromMap(
                self::mergeActivity($this->levelActivity->toMap(), $other->levelActivity->toMap()),
            ),
            exclusions: new RuleExclusionStats(
                namespaceExclusionsByRule: self::sumByRule(
                    $this->exclusions->namespaceExclusionsByRule,
                    $other->exclusions->namespaceExclusionsByRule,
                ),
                pathExclusionsByRule: self::sumByRule(
                    $this->exclusions->pathExclusionsByRule,
                    $other->exclusions->pathExclusionsByRule,
                ),
                excludedFindings: [...$this->exclusions->excludedFindings, ...$other->exclusions->excludedFindings],
            ),
        );
    }

    /**
     * The record is an answer about configuration, not a log of what executed:
     * it is asked of every registered rule, including ones this run never ran.
     * Two runs of the same configuration therefore answer the same way, the
     * two sides agree wherever they overlap, and `||` is the identity on them. It is
     * written as a merge rather than as "take either side" because nothing
     * makes the two configurations equal by type: a future caller merging runs
     * of *different* configurations gets the honest answer — the merged value
     * says what any of the merged runs was able to do — instead of silently
     * inheriting whichever side was written first.
     *
     * @param array<string, array<string, bool>> $left
     * @param array<string, array<string, bool>> $right
     *
     * @return array<string, array<string, bool>>
     */
    private static function mergeActivity(array $left, array $right): array
    {
        foreach ($right as $producer => $levels) {
            foreach ($levels as $level => $ran) {
                $left[$producer][$level] = ($left[$producer][$level] ?? false) || $ran;
            }
        }

        return $left;
    }

    /**
     * @param array<string, int> $left
     * @param array<string, int> $right
     *
     * @return array<string, int>
     */
    private static function sumByRule(array $left, array $right): array
    {
        foreach ($right as $ruleName => $count) {
            $left[$ruleName] = ($left[$ruleName] ?? 0) + $count;
        }

        return $left;
    }
}
