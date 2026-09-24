<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\FileSetInspection;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;

/**
 * Whether a run will ask this producer for findings at all — the one question
 * the expensive preparation phases hang on.
 *
 * It is asked of both switches an author has, not only of the selectors.
 * `--disable-rule X` and `--only-rule` reach {@see RuleSelector}; the rule's
 * own `enabled: false` — written as `rules: {X: {enabled: false}}`, as the
 * `rules: {X: false}` shorthand, or as `--rule-opt X:enabled=false` — never
 * did, so a producer switched off that way ran its preparation in full and had
 * every finding of it filtered away afterwards. The memory-intensive phases
 * are the ones this cost, and they are documented as the cure for running out
 * of memory.
 *
 * **What the options half does not see.** The key it reads is the one
 * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey::ENABLED}
 * names, and a producer whose switch is a different key answers "on" here.
 * `architecture.unassigned-class` is that producer: `mode` is its only switch
 * and `mode: ignore` — its default — leaves this gate saying the producer is
 * active. The selectors have always answered the same way about it, so the two
 * spellings still agree; what neither spelling reaches is a producer that is
 * off by its own default.
 *
 * **Only a written `false` closes the gate.** Any other value, including the
 * string `"false"` a hand-written YAML scalar can be, leaves the producer
 * active: an option this gate misreads as "off" loses findings silently, while
 * one it misreads as "on" only costs the work the run was doing yesterday.
 */
final readonly class RuleSelectorProducerGate
{
    /**
     * The key {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey::ENABLED}
     * declares. Every rule but `architecture.unassigned-class` answers to it.
     */
    private const string ENABLED_OPTION = 'enabled';

    public function __construct(private RuleSelector $ruleSelector) {}

    /**
     * @param list<string> $onlyRules
     * @param list<string> $disabledRules
     * @param array<string, mixed> $ruleOptions per-producer options as the run resolved them,
     *                                          straight from
     *                                          {@see \Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface::all()}
     */
    public function isEnabled(
        string $producerRuleName,
        array $onlyRules,
        array $disabledRules,
        array $ruleOptions,
    ): bool {
        if (self::switchedOffByItsOwnOptions($producerRuleName, $ruleOptions)) {
            return false;
        }

        return $this->ruleSelector->isProducerEnabled($producerRuleName, $onlyRules, $disabledRules);
    }

    /**
     * The author's own switch, in the two shapes it arrives in: the scalar
     * `rules: {X: false}` that {@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory}
     * normalises into `enabled: false` later, and the written key itself.
     *
     * @param array<string, mixed> $ruleOptions
     */
    private static function switchedOffByItsOwnOptions(string $producerRuleName, array $ruleOptions): bool
    {
        $options = $ruleOptions[$producerRuleName] ?? null;

        if ($options === false) {
            return true;
        }

        return \is_array($options) && ($options[self::ENABLED_OPTION] ?? null) === false;
    }
}
