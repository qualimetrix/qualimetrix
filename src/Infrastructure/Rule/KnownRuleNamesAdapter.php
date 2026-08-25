<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Analysis\Configuration\Contract\KnownRuleNamesProviderInterface;

/**
 * Hands the configuration pipeline the set of names a `rules:` key may address.
 *
 * The list arrives finished, from
 * {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}.
 * It used to be derived here by reflecting over rule classes, which was a
 * second enumeration of "every registered rule" and stopped being complete the
 * moment a producer existed without a class: the six built-in health dimensions
 * are addressable names that no `NAME` constant declares. Injecting the same
 * list the channel universe is keyed by leaves one authority instead of two.
 *
 * The pipeline cannot ask the universe itself: {@see \Qualimetrix\Analysis\Configuration\Pipeline\RuleNameValidator}
 * runs inside the stage that reads `qmx.yaml`, and the universe's run-time half
 * is resolved from that very document.
 */
final readonly class KnownRuleNamesAdapter implements KnownRuleNamesProviderInterface
{
    /**
     * @param list<string> $ruleNames every addressable producer name
     */
    public function __construct(
        private array $ruleNames,
    ) {}

    public function getKnownRuleNames(): array
    {
        return $this->ruleNames;
    }
}
