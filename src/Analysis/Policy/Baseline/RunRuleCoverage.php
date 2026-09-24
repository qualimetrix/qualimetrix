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
 * rule's configuration switched off, leaves the producer running and that one
 * channel-level pair unpublished. An entry there is absent because nothing
 * looked, not because nothing was found.
 *
 * The answer is asked of the run's own execution, per channel and per the
 * level the entry's subject measures at, rather than re-derived from the
 * selectors.
 */
final readonly class RunRuleCoverage
{
    public function __construct(
        private RuleExecutionInterface $execution,
        private ChannelIdentityInterface $channels,
    ) {}

    /**
     * The keys of the identities among `$identities` this run would not have
     * published. An identity on a channel no rule declares has no producer to
     * ask about and is never listed: that absence has a cause of its own.
     *
     * @param iterable<BaselineIdentity> $identities read by the loader, whose
     *                                               subject keys are therefore canonical
     *
     * @return array<string, true>
     */
    public function unmeasured(iterable $identities): array
    {
        $publication = $this->execution->publication();
        $unmeasured = [];

        foreach ($identities as $identity) {
            $producer = $this->channels->producerOf($identity->channel->code);

            if ($producer === null) {
                continue;
            }

            $level = MetricSubject::levelOfCanonical($identity->subjectKey);

            if (!$publication->publishes($producer, $identity->channel, $level)) {
                $unmeasured[$identity->key()] = true;
            }
        }

        return $unmeasured;
    }
}
