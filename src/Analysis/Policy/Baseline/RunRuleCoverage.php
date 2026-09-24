<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;

/**
 * The rule axis of what a run measured, beside {@see RunScope}'s path axis.
 *
 * An identity absent from the measured set says something about the code only
 * when the rule producing its channel ran. `--only-rule`, `--disable-rule` and a
 * rule configured `enabled: false` each leave a producer out of the run, and an
 * entry on its channel is then absent because nothing looked, not because
 * nothing was found.
 *
 * The answer is read off the run's own execution — its producer selection and
 * its per-level configuration — rather than re-derived from the selectors, the
 * same pair the inline-directive audit reads for the same question.
 *
 * Producer granularity only: a selector narrowed to one channel or one level of
 * a producer that still runs is not seen here, and an entry on such a channel
 * still reads as unreported.
 */
final readonly class RunRuleCoverage
{
    public function __construct(
        private RuleExecutionInterface $execution,
        private ChannelIdentityInterface $channels,
    ) {}

    /**
     * The codes among `$channels` whose producer this run did not execute. A
     * channel no rule declares has no producer to ask about and is never
     * listed: that absence has a cause of its own.
     *
     * @param iterable<FindingChannel> $channels
     *
     * @return array<string, true>
     */
    public function unproducedChannels(iterable $channels): array
    {
        $skipped = $this->skippedProducers();
        $unproduced = [];

        foreach ($channels as $channel) {
            $producer = $this->channels->producerOf($channel->code);

            if ($producer !== null && isset($skipped[$producer])) {
                $unproduced[$channel->code] = true;
            }
        }

        return $unproduced;
    }

    /**
     * @return array<string, true>
     */
    private function skippedProducers(): array
    {
        $activity = $this->execution->levelActivity();
        $skipped = [];

        foreach ($this->execution->allRules() as $rule) {
            if (!$rule->active) {
                $skipped[$rule->name] = true;
            }
        }

        foreach (array_keys($activity->toMap()) as $producer) {
            if ($activity->disabledEverywhere($producer)) {
                $skipped[$producer] = true;
            }
        }

        return $skipped;
    }
}
