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

    public function record(string $producer, FindingChannel $channel, SymbolLevel $level, PopulationIdentity $identity, ChannelDeclaration $declaration, ?string $failedGateId, ?string $reason): void
    {
        if (!$this->publication->publishes($producer, $channel, $level, $this->addressedProducer)) {
            return;
        }
        $gates = $declaration->gatesFor($channel, $level);
        if ($failedGateId === null) {
            if ($reason !== null || ($gates !== [] && $identity->unit !== $gates[0]->unit)) {
                throw new LogicException('Healthy population result has an invalid reason or member unit.');
            }
        } else {
            $failed = null;
            foreach ($gates as $gate) {
                if ($gate->id === $failedGateId) {
                    $failed = $gate;
                    break;
                }
            }
            if ($failed === null || $reason === null || trim($reason) === ''
                || $identity->unit !== ($failed->failureUnit ?? $failed->unit)) {
                throw new LogicException('Population failure must use its declared gate, reason and failed unit.');
            }
        }
        $this->trace->record($producer, $channel, $level, $identity, $failedGateId, $reason);
    }

    public function freeze(): JudgedPopulation
    {
        return $this->trace->freeze();
    }
}
