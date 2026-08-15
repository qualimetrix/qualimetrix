<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\FileSetInspection;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;

final readonly class RuleSelectorProducerGate
{
    public function __construct(private RuleSelector $ruleSelector) {}

    /**
     * @param list<string> $onlyRules
     * @param list<string> $disabledRules
     */
    public function isEnabled(string $producerRuleName, array $onlyRules, array $disabledRules): bool
    {
        return $this->ruleSelector->isProducerEnabled($producerRuleName, $onlyRules, $disabledRules);
    }
}
