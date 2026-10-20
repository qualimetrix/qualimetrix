<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\FileSetInspection;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;

/** Asks the run's completed enablement whether a producer needs preparation. */
final readonly class RuleSelectorProducerGate
{
    public function __construct(private RuleConfigurationInterface $ruleConfiguration) {}

    public function isEnabled(string $producerRuleName): bool
    {
        return $this->ruleConfiguration->enablement()?->runs($producerRuleName)
            ?? throw new LogicException('Rule enablement is unavailable before analysis preflight.');
    }
}
