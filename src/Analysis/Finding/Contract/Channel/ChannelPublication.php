<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Channel;

use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Population\PopulationSession;
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
 *
 * @qmx-threshold coupling.cbo 22 -- This public fact or option contract combines its declaration vocabulary with bounded implementation dependencies; its incoming readers do not independently justify a popularity exception.
 */
final readonly class ChannelPublication
{
    public function __construct(
        private RuleEnablement $enablement,
    ) {}

    public function publishes(string $producer, FindingChannel $channel, SymbolLevel $level, ?string $addressedProducer = null): bool
    {
        return $this->enablement->publishes($channel, $level, $addressedProducer);
    }
    /** @param iterable<array{identity: PopulationIdentity, inputs: iterable<GateInput>}> $members */
    public function measure(
        string $producer,
        FindingChannel $channel,
        SymbolLevel $level,
        ChannelDeclaration $declaration,
        iterable $members,
        ?string $addressedProducer = null,
    ): JudgedPopulation {
        if (!$this->publishes($producer, $channel, $level, $addressedProducer)) {
            return JudgedPopulation::empty();
        }
        $session = new PopulationSession(($this)->publishes(...), $addressedProducer);
        foreach ($members as $member) {
            $failed = $declaration->populationFailure($channel, $level, $member['identity'], $member['inputs']);
            $session->record($producer, $channel, $level, $member['identity'], $declaration, $failed['gate'] ?? null, $failed['reason'] ?? null, $failed['dataGap'] ?? true);
            unset($member);
        }
        unset($members);
        return $session->freeze();
    }

}
