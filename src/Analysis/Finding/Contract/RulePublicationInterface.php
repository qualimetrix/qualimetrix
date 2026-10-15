<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Channel\LevelActivity;

/** Publishes findings and channel activity from the resolved invocation. */
interface RulePublicationInterface
{
    /**
     * The subset of findings this run's selection publishes when a caller
     * assembles them after rule execution. The directive-usage audit's
     * `annotation.unused-directive` is one such finding: it can only be
     * answered after every rule has produced its findings.
     *
     * This uses the same {@see RuleEnablement} selection projection as rule
     * execution. It does not update the per-rule exclusion ledger, because
     * late findings did not pass through that part of execution.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public function publishable(array $findings): array;

    /**
     * What this run publishes for a reader holding a channel identity rather
     * than a finding, such as a baseline entry the run did not report.
     */
    public function publication(): ChannelPublication;

    /**
     * What this configuration lets each producer do per declared level.
     * This can be asked without executing rules; execution also records the
     * answer beside its findings.
     */
    public function levelActivity(): LevelActivity;
}
