<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Configuration\KnownRuleNamesProviderInterface;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\RuleNameReader;

/**
 * Adapter that extracts known rule names from registered rule classes.
 *
 * Bridges the Infrastructure layer's RuleRegistry to the Configuration
 * layer's KnownRuleNamesProviderInterface, enabling rule name validation
 * in configuration stages (ConfigFileStage, PresetStage).
 *
 * Uses reflection on NAME constants — does not instantiate rules.
 */
final readonly class KnownRuleNamesAdapter implements KnownRuleNamesProviderInterface
{
    /**
     * @param list<class-string<RuleInterface>> $ruleClasses
     */
    public function __construct(
        private array $ruleClasses,
    ) {}

    public function getKnownRuleNames(): array
    {
        $names = [];

        foreach ($this->ruleClasses as $ruleClass) {
            $names[] = RuleNameReader::read($ruleClass);
        }

        return $names;
    }
}
