<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;

/**
 * Executes analysis rules with runtime filtering.
 *
 * This interface decouples rule execution from the Analyzer,
 * allowing rules to be filtered at runtime based on configuration
 * (disabled_rules, only_rules) without affecting DI container setup.
 *
 * **A registered rule here is a producer, not a class.** {@see allRules()}
 * enumerates every name a finding can be published under, which is a larger
 * set than the rule classes the container instantiates: the computed-metric
 * family runs in one class and publishes under seven producer names.
 * Execution is still per instance — a producer without a class has nothing to
 * run — so only {@see execute()} is keyed by instance.
 */
interface RuleExecutionInterface
{
    /**
     * Executes all active rules and returns what happened.
     *
     * `$restrictToProducer` **narrows** the run's own selection and never
     * widens it: a producer the configuration disabled stays disabled even
     * when named here. It exists for the threshold audit, which asks what one
     * `@qmx-threshold` did and can only be answered about the single rule the
     * directive addresses by exact name — executing the other forty-eight
     * rules to compare them against themselves is the cost the narrowing
     * removes.
     *
     * A name rather than a {@see RuleSelection} because one name is the whole
     * subject: a directive addresses exactly one rule, by exact equality —
     * never the broader selector grammar `--only-rule` accepts (a glob, or a
     * match by channel code). The host of a classless producer still runs
     * when the narrowing names one of the producers it hosts, exactly as
     * `--only-rule` makes it run.
     *
     * **What is reachable from the one production caller today.** The
     * threshold audit is the sole caller that ever passes a non-null name,
     * and it always passes the exact name of the rule an authored
     * `@qmx-threshold` addresses. Two things follow that this signature does
     * not itself guarantee, and are true only because of that caller:
     * a classless producer can never be named here, because
     * `ComputedMetricChannelFamily::SUPPORTS_THRESHOLD_OVERRIDE` is `false`
     * and no other producer of the family declares support either — so the
     * "host of a classless producer" branch above is exercised only by unit
     * tests, never by a running audit. And the `published` half of the
     * returned result is read by nothing downstream of that caller — it asks
     * only for `->produced` — so a narrowed execution's channel filtering
     * ({@see \Qualimetrix\Analysis\Finding\RuleExecution::published()})
     * currently has no reader either. Both stay defined and tested because
     * the contract narrows **execution**, not visibility, and either fact
     * changes the moment a second caller narrows for a different reason.
     */
    public function execute(AnalysisContext $context, ?string $restrictToProducer = null): RuleExecutionResult;

    /**
     * The subset of `$findings` this run's selection publishes, for findings a
     * caller assembles **outside** {@see execute()}.
     *
     * One channel is assembled that way today — the directive-usage audit's
     * `annotation.unused-directive`, which can only be answered once every rule
     * has produced its findings. Being assembled late used to mean being
     * assembled past the filter: naming that channel in `--disable-rule` left it
     * reported, and an `only_rules` that never named it reported it anyway.
     * Both halves leaked, and both leaked silently.
     *
     * The predicate is the same object and the same call
     * {@see \Qualimetrix\Analysis\Finding\RuleExecution::published()} makes,
     * not a second reading of the selection: the union-quantified half of the
     * grammar (a producer stopped because its disable selectors together cover
     * every declared level of every channel it emits) lives in
     * {@see Rule\RuleSelector} and must not be re-derived per selector by a
     * caller.
     *
     * The per-rule exclusion ledger is deliberately **not** applied here; see
     * the implementation for the accounting that forbids it.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public function publishable(array $findings): array;

    /**
     * Every registered producer, each carrying whether the resolved selection
     * leaves it enabled.
     *
     * One answer, not three: the enabled subset and the count were separate
     * operations that nothing outside tests ever asked for, and three
     * enumerations of "every registered rule" are three chances to disagree.
     * A caller that wants either filters or counts this list.
     *
     * @return list<RuleMetadata>
     */
    public function allRules(): array;

    /**
     * What this configuration lets each producer do, per declared level.
     *
     * A fact about configuration rather than about a run, which is why it can
     * be asked without executing anything: {@see execute()} records the same
     * answer on its result so consumers read it beside the findings it
     * explains. Asked separately, it is also what lets a guard check the
     * snapshot against the channel declarations without needing a prepared
     * policy for every producer.
     */
    public function levelActivity(): LevelActivity;
}
