<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Population;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Core\Symbol\SymbolLevel;

final class PopulationSession
{
    private readonly PopulationTrace $trace;

    public function __construct(private readonly ChannelPublication $publication, private readonly ?string $addressedProducer = null)
    {
        $this->trace = new PopulationTrace();
    }

    public function record(string $producer, FindingChannel $channel, SymbolLevel $level, PopulationIdentity $identity, ChannelDeclaration $declaration, ?string $failedGate, ?string $reason): void
    {
        if (!$this->publication->publishes($producer, $channel, $level, $this->addressedProducer)) {
            return;
        }
        foreach ($declaration->gatesFor($channel, $level) as $gate) {
            if ($identity->unit !== 'invocation' && $gate->unit !== $identity->unit) {
                throw new LogicException('Population member and declared gate have different units.');
            }
        }
        $this->trace->record($producer, $channel, $level, $identity, $failedGate, $reason);
    }

    public function freeze(): JudgedPopulation
    {
        return $this->trace->freeze();
    }
}
