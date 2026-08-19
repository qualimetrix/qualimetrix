<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;

/**
 * The identity half of the channel universe: which names exist, what each
 * name belongs to, and which declared properties hang off them.
 *
 * This view answers questions **about** names. It never answers "does this
 * user string select that finding" — matching is string comparison, lives in
 * {@see NameSelector}, and deliberately never consults a registry. The one
 * place the two meet is {@see expand()}, which is not matching but resolution:
 * a `X.*` selector is turned into the concrete channels it stands for, which
 * only the universe can know.
 *
 * Two kinds of entity are addressable, and the addressing directive decides
 * which one is meant — never the number of dot-separated segments:
 *
 * - a **rule** is addressed by `@qmx-threshold` and by rule-option ownership;
 * - a **channel** is addressed by the three inline suppression directives.
 *
 * A channel's `ruleName` half is not necessarily a rule: the layer policy
 * emits channels under rule names no class declares as its own. That is
 * why {@see producerOf()} exists and why a "did you mean" suggestion must be
 * a reverse query here rather than a suffix stripped off the typed string —
 * stripping `.class` off `coupling.cbo.class` happens to work, and stripping
 * anything off `architecture.coverage` does not.
 */
interface ChannelIdentityInterface
{
    /**
     * Every registered producer rule name, whether or not it declares
     * channels and whether or not it is enabled for this run: enablement is
     * an execution filter, not a fact about which names exist.
     *
     * @return list<string>
     */
    public function ruleNames(): array;

    public function hasRule(string $ruleName): bool;

    /**
     * Every channel of this configuration — the statically declared ones and
     * the computed-metric ones resolved from the configured definitions.
     *
     * @return list<ViolationChannel>
     */
    public function channels(): array;

    public function hasChannel(string $violationCode): bool;

    /**
     * The rule that produces the channel with this violation code, or `null`
     * when no channel carries that code.
     *
     * The answer is the **producing rule**, which may differ from the
     * channel's own `ruleName` half.
     */
    public function producerOf(string $violationCode): ?string;

    /**
     * Whether the rule declares that `@qmx-threshold` can retune it.
     *
     * Declared by the rule, never inferred from whether its options class
     * happens to implement a threshold interface: the inference could not be
     * queried in reverse, so no diagnostic could name the rules a mistyped
     * directive should have addressed.
     */
    public function supportsThresholdOverride(string $ruleName): bool;

    /**
     * Resolves a selector into the concrete channels it addresses, by
     * violation code: exactly one for an equality selector, the strict
     * descendants for the `X.*` form, and none when the selector covers
     * nothing.
     *
     * @return list<ViolationChannel>
     */
    public function expand(NameSelector $selector): array;
}
