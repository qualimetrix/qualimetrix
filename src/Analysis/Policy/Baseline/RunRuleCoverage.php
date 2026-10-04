<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * The rule axis of what a run measured, beside {@see RunScope}'s path axis.
 *
 * An identity absent from the measured set says something about the code only
 * when the run would have published it. `--only-rule`, `--disable-rule` and a
 * rule configured `enabled: false` each leave a producer out of the run; a
 * selector narrowed to a level or a channel (`X:namespace`), or a level the
 * rule's configuration switched off, leaves that channel-level pair
 * unpublished. A stored subject may also be at a level the channel no longer
 * declares. Neither absence says the code improved.
 *
 * The declared levels come from the current channel universe; publication
 * comes from the run's own execution, rather than being re-derived from the
 * selectors.
 */
final readonly class RunRuleCoverage
{
    public function __construct(
        private RuleExecutionInterface $execution,
        private ChannelIdentityInterface $channels,
    ) {}

    /**
     * Classify identities this run could not publish. An identity on a
     * channel no rule declares has no producer to ask about and is omitted:
     * that absence has a cause of its own.
     *
     * @param iterable<BaselineIdentity> $identities read by the loader, whose
     *                                               subject keys are therefore canonical
     *
     * @return array<string, RunCoverageGap> keyed by baseline identity
     */
    public function classify(iterable $identities): array
    {
        $publication = $this->execution->publication();
        $gaps = [];

        foreach ($identities as $identity) {
            $producer = $this->channels->producerOf($identity->channel->code);

            if ($producer === null) {
                continue;
            }

            $level = MetricSubject::levelOfCanonical($identity->subjectKey);

            if (!\in_array($level, $this->channels->levelsOf($identity->channel->code), true)) {
                $gaps[$identity->key()] = RunCoverageGap::LevelNotDeclared;

                continue;
            }

            if (!$publication->publishes($producer, $identity->channel, $level)) {
                $gaps[$identity->key()] = RunCoverageGap::NotMeasured;
            }
        }

        return $gaps;
    }
}
