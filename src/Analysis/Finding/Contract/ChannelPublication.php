<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Whether a run publishes one producer's channel at one level, answered for a
 * reader that holds an identity rather than a finding.
 *
 * Both switches are asked because they live in different places: a selector
 * narrowed to a level or a channel (`X:namespace`) is part of the selection,
 * which {@see LevelActivity} never sees, and a level switched off in the rule's
 * own options (`class: { enabled: false }`) is part of the activity, which the
 * selection never sees. Either alone would call the level the other one
 * stopped published.
 */
final readonly class ChannelPublication
{
    public function __construct(
        private RuleSelector $selector,
        private RuleSelection $selection,
        private LevelActivity $activity,
    ) {}

    public function publishes(string $producer, FindingChannel $channel, SymbolLevel $level): bool
    {
        return $this->selector->isChannelEnabled(
            $producer,
            $channel,
            $level,
            $this->selection->only,
            $this->selection->disabled,
        )
            && $this->activity->ranAtAnyOf($producer, [$level]);
    }
}
