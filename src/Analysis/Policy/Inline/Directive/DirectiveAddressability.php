<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;

/**
 * Whether a directive addresses something it is allowed to address, and — when
 * it does not — what to say about it.
 *
 * The question is deliberately about **addressability**, not existence. A
 * threshold naming a rule that declares no override support resolves perfectly
 * well and still cannot ever do anything, which is the same lie as a typo.
 *
 * It answers against the universe of the run's *resolved* configuration, so a
 * channel that exists only because the user defined a computed metric is as
 * addressable as a statically declared one. Enablement plays no part: a
 * disabled rule's name still exists.
 *
 * Kept out of the rule because it decides *what is wrong*, while the rule
 * decides *what to report and on which channel*. The rule keeps every
 * `new Finding(...)` so the emission guard can still read the channel of
 * each one off a `self::` constant.
 */
final readonly class DirectiveAddressability
{
    private DirectiveNameHints $hints;

    public function __construct(
        private ChannelIdentityInterface $identity,
    ) {
        $this->hints = new DirectiveNameHints($identity);
    }

    /**
     * A suppression addresses a **channel**. Four spellings are legitimate:
     * the absence of a rule filter, an exact channel name, the explicit
     * `ruleName#violationCode` pair, and `X.*` for the channels below `X`.
     * Everything else names nothing.
     */
    public function problemWithSuppression(Suppression $suppression): ?string
    {
        $target = $suppression->target();
        if ($target->appliesToEveryChannel()) {
            return null;
        }

        $raw = (string) $target;

        if ($raw === Suppression::REASON_SEPARATOR) {
            return \sprintf(
                'Suppression "%s" names no channel. Only @qmx-ignore-file may leave the channel out; on'
                . ' @qmx-ignore and @qmx-ignore-next-line write the channel first, then "%s" and the reason.',
                $raw,
                Suppression::REASON_SEPARATOR,
            );
        }

        if ($target->looksLikeChannelPair()) {
            return $this->problemWithChannelPair($target->exactChannel(), $raw);
        }

        $selector = NameSelector::tryParse($raw);
        if ($selector === null) {
            return \sprintf(
                'Suppression "%s" is not a channel selector. Write an exact channel name,'
                . ' "ruleName#violationCode", or "X.*" for the channels below X.'
                . ' A reason goes after "%s".',
                $raw,
                Suppression::REASON_SEPARATOR,
            );
        }

        if ($this->identity->expand($selector) !== []) {
            return null;
        }

        return \sprintf(
            'Suppression "%s" addresses no channel. %s Prose belongs after "%s".',
            $raw,
            $this->hints->forChannelSelector($selector),
            Suppression::REASON_SEPARATOR,
        );
    }

    /**
     * The explicit pair resolves against the channel universe directly: both
     * halves are exact, so there is nothing to expand.
     */
    private function problemWithChannelPair(?FindingChannel $pair, string $raw): ?string
    {
        if ($pair === null) {
            return \sprintf(
                'Suppression "%s" is not a channel selector. The "ruleName#violationCode" form takes exactly'
                . ' two exact halves and no "*" in either of them.',
                $raw,
            );
        }

        foreach ($this->identity->channels() as $channel) {
            if ($channel->equals($pair)) {
                return null;
            }
        }

        return \sprintf(
            'Suppression "%s" addresses no channel. %s',
            $raw,
            $this->hints->forChannelPair($pair->ruleName, $pair->code),
        );
    }

    /**
     * A threshold addresses a **rule**, by its exact name. The name resolving
     * to nothing is one mistake; it resolving to a rule that cannot be
     * retuned is a different one, so the caller is told which.
     */
    public function problemWithThreshold(ThresholdOverride $override): ?DirectiveRejection
    {
        $name = $override->rulePattern;

        if (!$this->identity->hasRule($name)) {
            return new DirectiveRejection(
                false,
                \sprintf('@qmx-threshold "%s" names no rule. %s', $name, $this->hints->forRuleName($name)),
            );
        }

        if ($this->identity->supportsThresholdOverride($name)) {
            return null;
        }

        return new DirectiveRejection(true, \sprintf(
            'Rule "%s" declares no @qmx-threshold support, so this annotation can never do anything.'
            . ' Remove it, or configure the rule under its "rules:" key.',
            $name,
        ));
    }

    /**
     * The extractor already decided this one; only the wording is left.
     *
     * The validator's stable code used to be spliced into the finding code,
     * which turned every new validator outcome into a channel nobody declared.
     * It is data about the finding, so it is reported as data.
     */
    public function describeDiagnostic(ThresholdDiagnostic $diagnostic): string
    {
        return $diagnostic->code === null
            ? $diagnostic->message
            : \sprintf('%s [%s]', $diagnostic->message, $diagnostic->code);
    }
}
