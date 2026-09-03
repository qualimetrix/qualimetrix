<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
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
 *
 * @qmx-threshold coupling.instability warning=0.85 error=0.85 -- Ca=2, Ce=10: this class answers for the whole directive vocabulary, so it depends outward on every part of it and is depended on by the two halves that ask. The measured 0.83 moved by one dependency when the ban arrived and stays structural; the second dependent is what made an always-structural value reportable at all, since the class rule needs min_afferent=2. Same shape as VisitorMethodContext's composition root.
 */
final readonly class DirectiveAddressability
{
    private DirectiveNameHints $hints;

    /**
     * The shared refusal for a `channel:level` pair. Built here rather than
     * injected for the same reason the hints are: a pure function of the same
     * universe, with no lifecycle of its own.
     */
    private ChannelLevelAddressing $levels;

    /**
     * The channel no directive may address, built here for the same reason as
     * the two above and asked by {@see \Qualimetrix\Analysis\Policy\Inline\Directive\Audit\DirectiveUsage} as well: the
     * refusal an author reads and the reason the audit reports are the same
     * answer, and deriving it twice would be two chances to disagree.
     */
    private DirectiveChannelBan $ban;

    public function __construct(
        private ChannelIdentityInterface $identity,
    ) {
        $this->hints = new DirectiveNameHints($identity);
        $this->levels = new ChannelLevelAddressing($identity);
        $this->ban = new DirectiveChannelBan($identity);
    }

    /**
     * A suppression addresses a **channel**, optionally at one level. Four
     * spellings are legitimate: the absence of a rule filter, an exact channel
     * name, `X.*` for the channels below `X`, and either of the last two with
     * `:level` after it. Everything else names nothing.
     *
     * An impossible pair is refused by {@see ChannelLevelAddressing} and by
     * nothing here: the configuration seam asks the same question of the same
     * object, so the two families of directive cannot answer it differently.
     *
     * {@see DirectiveChannelBan} is asked **last**, and the order is load
     * bearing rather than incidental: `annotation.unused-directive:class`
     * names a level that channel never reports at, and a ban asked first would
     * answer that mistake with the wrong sentence — the author would be told
     * the channel may not be addressed when what they wrote is a pair that
     * cannot exist.
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

        if ($target->usesRetiredChannelPair()) {
            return \sprintf(
                'Suppression "%s" is written in the retired channel-pair form. %s A reason goes after "%s".',
                $raw,
                FindingChannel::retiredPairAdvice($raw),
                Suppression::REASON_SEPARATOR,
            );
        }

        $pairProblem = $this->levels->problemWith($raw, \sprintf('Suppression "%s"', $raw));
        if ($pairProblem !== null) {
            return \sprintf('%s A reason goes after "%s".', $pairProblem, Suppression::REASON_SEPARATOR);
        }

        $selector = $target->selector();
        if ($selector === null) {
            return \sprintf(
                'Suppression "%s" is not a channel selector. Write an exact channel name,'
                . ' or "X.*" for the channels below X, either optionally followed by "%slevel".'
                . ' A reason goes after "%s".',
                $raw,
                ChannelLevelSelector::LEVEL_SEPARATOR,
                Suppression::REASON_SEPARATOR,
            );
        }

        if ($this->identity->expand($selector->channel()) === []) {
            return \sprintf(
                'Suppression "%s" addresses no channel. %s Prose belongs after "%s".',
                $raw,
                $this->hints->forChannelSelector($selector->channel()),
                Suppression::REASON_SEPARATOR,
            );
        }

        return $this->ban->problemWith($raw, $selector->channel());
    }

    /**
     * A threshold addresses a **rule**, by its exact name. The name resolving
     * to nothing is one mistake; it resolving to a rule that cannot be
     * retuned is a different one, so the caller is told which.
     */
    public function problemWithThreshold(ThresholdOverride $override): ?DirectiveRejection
    {
        $name = $override->rulePattern;

        if (FindingChannel::isRetiredPairSpelling($name)) {
            return new DirectiveRejection(false, \sprintf(
                '@qmx-threshold "%s" is written in the retired channel-pair form. %s A threshold addresses the'
                . ' producing rule by its own name.',
                $name,
                FindingChannel::retiredPairAdvice($name),
            ));
        }

        // Routed through the shared pair grammar so the checking order — is the
        // right half a level at all, then is the left half a rule, then the
        // porous case where both are but a threshold still cannot use either —
        // lives in one place and cannot drift from the suppression family's.
        $pairProblem = $this->levels->problemWithRulePair($name, \sprintf('@qmx-threshold "%s"', $name));
        if ($pairProblem !== null) {
            return new DirectiveRejection(false, $pairProblem);
        }

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
